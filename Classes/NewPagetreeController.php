<?php

declare(strict_types=1);

namespace MichielRoos\WizardCrpagetree;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * @phpstan-type PageData array<int,string>
 * // data?: mixed is wrong here and should be PageTreeStructure, but is not allowed by phpstan
 * @phpstan-type PageTreeData array{data?: mixed, value?: string}
 * @phpstan-type PageTreeStructure array<int,PageTreeData>
 */
#[AsController]
class NewPagetreeController
{
    protected ServerRequestInterface $request;

    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        protected IconFactory $iconFactory,
        protected ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * Main function Handling input variables and rendering main view
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        $this->moduleTemplate = $this->moduleTemplateFactory->create($request);
        $backendUser = $this->getBackendUser();
        $pageUid = (int)$request->getQueryParams()['id'];

        // Show only if there is a valid page and if this page may be viewed by the user
        $pageRecord = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (!is_array($pageRecord)) {
            // User has no permission on parent page, should not happen, just render an empty page
            return $this->moduleTemplate->renderResponse('NewPagetree/Main');
        }

        // Doc header handling
        $this->moduleTemplate->getDocHeaderComponent()->setMetaInformation($pageRecord);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $previewDataAttributes = PreviewUriBuilder::create($pageUid)
            ->withRootLine(BackendUtility::BEgetRootLine($pageUid))
            ->buildDispatcherDataAttributes();
        $viewButton = $buttonBar->makeLinkButton()
            ->setDataAttributes($previewDataAttributes ?? [])
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.showPage'))
            ->setIcon($this->iconFactory->getIcon('actions-view-page', IconSize::SMALL))
            ->setHref('#');
        $buttonBar->addButton($viewButton);

        // Main view setup
        $view = $this->moduleTemplate;

        $calculatedPermissions = new Permission($backendUser->calcPerms($pageRecord));
        $canCreateNew = $backendUser->isAdmin() || $calculatedPermissions->createPagePermissionIsGranted();

        $view->assign('canCreateNew', $canCreateNew);
        $view->assign('maxTitleLength', $backendUser->uc['titleLen'] ?? 20);
        $view->assign('pageUid', $pageUid);

        if ($canCreateNew) {
            /** @var array{pageTree: ?string, createInListEnd: ?bool, hidePages: ?bool, hidePagesInMenus: ?bool} $parsedBody */
            $parsedBody = $request->getParsedBody();
            $newPagesData = $parsedBody['pageTree'] ?? '';
            if ($newPagesData !== '') {
                $newPagesData = explode("\r\n", $newPagesData);
                $newPagesData = $this->filterComments($newPagesData);

                $hasNewPagesData = true;
                $afterExisting = isset($parsedBody['createInListEnd']);
                $hidePages = isset($parsedBody['hidePages']);
                $hidePagesInMenu = isset($parsedBody['hidePagesInMenus']);
                $pagesCreated = $this->createPagetree($newPagesData, $pageUid, $afterExisting, $hidePages, $hidePagesInMenu);
                $view->assign('pagesCreated', $pagesCreated);
                $subPages = $this->getSubPagesOfPage($pageUid);
                $visiblePages = [];
                foreach ($subPages as $page) {
                    $calculatedPermissions = $backendUser->calcPerms($page);
                    if ($calculatedPermissions & Permission::PAGE_SHOW || $backendUser->isAdmin()) {
                        $visiblePages[] = $page;
                    }
                }
                $view->assign('visiblePages', $visiblePages);
            } else {
                $hasNewPagesData = false;
            }

            $view->assign('hasNewPagesData', $hasNewPagesData);
        }

        return $this->moduleTemplate->renderResponse('Page/NewPagetree');
    }

    /**
     * Persist new pages in DB
     *
     * @param PageData $newPagesData Data array with title and page type
     * @param int $pageUid Uid of page new pages should be added in
     * @param bool $afterExisting True if new pages should be created after existing pages
     * @param bool $hidePages True if new pages should be set to hidden
     * @param bool $hidePagesInMenu True if new pages should be set to hidden in menu
     * @return bool TRUE if at least on pages has been added
     */
    protected function createPagetree(array $newPagesData, int $pageUid, bool $afterExisting, bool $hidePages, bool $hidePagesInMenu): bool
    {
        $pagesCreated = false;

        // Set first pid to "-1 * uid of last existing sub-page" if pages should be created at end
        $firstPid = $pageUid;
        if ($afterExisting) {
            $subPages = $this->getSubPagesOfPage($pageUid);
            $lastPage = end($subPages);
            if (isset($lastPage['uid']) && MathUtility::canBeInterpretedAsInteger($lastPage['uid'])) {
                $firstPid = -(int)$lastPage['uid'];
            }
        }

        $commandArray = [];

        $parsedBody = (array)$this->request->getParsedBody();
        $ic = $this->getIndentationChar($parsedBody);
        $sc = $this->getSeparationChar($parsedBody);
        $ef = $this->getExtraFields($parsedBody);

        // Reverse the ordering of the data
        $originalData = $this->getArray($newPagesData, 0, $ic);
        $reversedData = $this->reverseArray($originalData);
        $data = $this->compressArray($reversedData);

        $pageIndex = count($data);
        $sorting = count($data);
        $oldLevel = 0;
        $parentPid = [];
        $currentPid = 0;
        foreach ($data as $line) {
            if (trim($line)) {
                // What level are we on?
                preg_match('/^' . $ic . '*/', $line, $regs);
                $level = strlen($regs[0]);

                if ($level === 0) {
                    $currentPid = $firstPid;
                    $parentPid[$level] = $firstPid;
                } elseif ($level > $oldLevel) {
                    $currentPid = 'NEW' . ($pageIndex - 1);
                    $parentPid[$level] = $pageIndex - 1;
                } elseif ($level === $oldLevel) {
                    $currentPid = 'NEW' . $parentPid[$level];
                } elseif ($level < $oldLevel) {
                    $currentPid = 'NEW' . $parentPid[$level];
                }

                // Get title and additional field values
                $parts = GeneralUtility::trimExplode($sc, $line);

                $commandArray['pages']['NEW' . $pageIndex]['title'] = ltrim($parts[0], $ic);
                $commandArray['pages']['NEW' . $pageIndex]['pid'] = $currentPid;
                $commandArray['pages']['NEW' . $pageIndex]['sorting'] = $sorting--;
                $commandArray['pages']['NEW' . $pageIndex]['hidden'] = (int)$hidePages;
                $commandArray['pages']['NEW' . $pageIndex]['nav_hide'] = (int)$hidePagesInMenu;

                // Drop the title
                array_shift($parts);

                // Add additional field values
                if ($ef) {
                    foreach ($ef as $index => $field) {
                        $commandArray['pages']['NEW' . $pageIndex][$field] = $parts[$index];
                    }
                }
                $oldLevel = $level;
                $pageIndex++;
            }
        }

        if ($commandArray) {
            $pagesCreated = true;
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            // Set default TCA values specific for the user
            $backendUser = $this->getBackendUser();
            $tcaDefaultOverride = $backendUser->getTSConfig()['TCAdefaults.'] ?? null;
            if (is_array($tcaDefaultOverride)) {
                $dataHandler->setDefaultsFromUserTS($tcaDefaultOverride);
            }
            $dataHandler->start($commandArray, []);
            $dataHandler->process_datamap();
            BackendUtility::setUpdateSignal('updatePageTree');
        }

        return $pagesCreated;
    }

    /**
     * Get a list of sub-pages with some all fields from given page.
     * Fetch all data fields for full page icon display
     *
     * @param int $pageUid Get sub-pages from this pages
     * @return list<array<string,int|string>>
     */
    protected function getSubPagesOfPage(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    $GLOBALS['TCA']['pages']['ctrl']['languageField'],
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return the data as a compressed array
     *
     * @param PageTreeStructure $data the uncompressed array
     *
     * @return   PageData      the data as a compressed array
     */
    private function compressArray(array $data): array
    {
        $newData = [];
        foreach ($data as $value) {
            if ($value['value'] ?? false) {
                $newData[] = $value['value'];
            }
            if ($value['data'] ?? false) {
                $newData = array_merge($newData, $this->compressArray($value['data']));
            }
        }

        return $newData;
    }

    /**
     * Return the data as a nested array
     *
     * @param PageData $data the data array
     * @param int $oldLevel the current level
     * @param string $character indentation character
     *
     * @return  PageTreeStructure      the data as a nested array
     */
    private function getArray(array $data, int $oldLevel = 0, string $character = ' '): array
    {
        $size = count($data);
        $newData = [];
        for ($i = 0; $i < $size;) {
            $regs = [];
            $v = $data[$i];
            if (trim($v)) {
                // What level are we on?
                preg_match('/^' . $character . '*/', $v, $regs);
                $level = strlen($regs[0]);

                if ($level > $oldLevel) {
                    /**
                     * We have entered a sublevel. Find the chunk of the array that
                     * constitutes this sublevel. Pass this chunk to the getArray
                     * function. Then increase the $i to point to the point where the
                     * level is the same as we are on now.
                     */
                    $subData = [];
                    for ($j = $i; $j < $size; $j++) {
                        $regs = [];
                        $v = $data[$j];
                        if (trim($v)) {
                            // What level are we on?
                            preg_match('/^' . $character . '*/', $v, $regs);
                            $subLevel = strlen($regs[0]);
                            if ($subLevel >= $level) {
                                $subData[] = $v;
                            } else {
                                break;
                            }
                        }
                    }
                    $newData[$i - 1]['data'] = $this->getArray($subData, $level, $character);
                    $i = $i + count($subData);
                } elseif (($level === 0) or ($level === $oldLevel)) {
                    $newData[$i]['value'] = $v;
                    $i++;
                }
                $oldLevel = $level;
            }
            if ($i === $size) {
                break;
            }
        }

        return $newData;
    }

    /**
     * Return the data with all the leaves sorted in reverse order
     *
     * @param PageTreeStructure $data input array
     *
     * @return   PageTreeStructure      the data reversed
     */
    private function reverseArray(array $data): array
    {
        $newData = [];
        $index = 0;
        foreach ($data as $chunk) {
            if (is_array($chunk['data'] ?? false)) {
                $newData[$index]['data'] = $this->reverseArray($chunk['data']);
                krsort($newData[$index]['data']);
            }
            $newData[$index]['value'] = $chunk['value'] ?? '';
            $index++;
        }
        krsort($newData);

        return $newData;
    }

    /**
     * Return the data without comment fields and empty lines
     *
     * @param PageData $data input array
     *
     * @return   PageData      the data reversed
     */
    private function filterComments(array $data): array
    {
        $newData = [];
        $multiLine = false;
        foreach ($data as $value) {
            // Multiline comment
            if (!$multiLine && preg_match('#^/\*#', $value)) {
                $multiLine = true;
                continue;
            }
            if ($multiLine && preg_match('#[*]+/#', ltrim($value))) {
                $multiLine = false;
                continue;
            }
            if ($multiLine) {
                continue;
            }
            // Single line comment
            if (str_starts_with(ltrim($value), '//') || str_starts_with(ltrim($value), '#')) {
                continue;
            }

            // Empty line
            if (!trim($value)) {
                continue;
            }

            $newData[] = $value;
        }

        return $newData;
    }

    /**
     * Get the indentation character (space, tab or dot)
     *
     * @param array<string,mixed> $params
     * @return   string      the indentation character
     */
    private function getIndentationChar(array $params): string
    {
        $character = $params['indentationCharacter'] ?? '';
        return match ($character) {
            'dot' => '\.',
            'tab' => '\t',
            default => ' ',
        };
    }

    /**
     * Get the separation character (, or | or ; or :)
     *
     * @param array<string,mixed> $params
     * @return   string      the separation character
     */
    private function getSeparationChar(array $params): string
    {
        $character = $params['separationCharacter'] ?? '';
        return match ($character) {
            'pipe' => '|',
            'semicolon' => ';',
            'colon' => ':',
            default => ',',
        };
    }

    /**
     * Get the extra fields
     *
     * @param array<string,mixed> $params
     * @return   string[]      the extra fields
     */
    private function getExtraFields(array $params): array
    {
        $efLine = $params['extraFields'] ?? '';
        if (trim($efLine)) {
            return GeneralUtility::trimExplode(' ', $efLine, true);
        }

        return [];
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
