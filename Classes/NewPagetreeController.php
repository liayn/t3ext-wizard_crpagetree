<?php
declare(strict_types=1);
namespace MichielRoos\WizardCrpagetree;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * "New page tree" controller
 *
 * Fluid template based backend module for TYPO3 9.5
 *
 */
class NewPagetreeController
{
    protected ServerRequestInterface $request;

    /**
     * Error messages collected from DataHandler during page creation
     *
     * @var string[]
     */
    protected array $dataHandlerErrors = [];

    protected IconFactory $iconFactory;
    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected LanguageServiceFactory $languageServiceFactory;

    public function __construct(
        IconFactory $iconFactory,
        ModuleTemplateFactory $moduleTemplateFactory,
        LanguageServiceFactory $languageServiceFactory,
    ) {
        $this->iconFactory = $iconFactory;
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->languageServiceFactory = $languageServiceFactory;
    }

    /**
     * Main function Handling input variables and rendering main view
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface Response
     * @throws Exception
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $backendUser = $this->getBackendUser();
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);

        // Show only if there is a valid page and if this page may be viewed by the user
        $pageRecord = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        $hasAccess = is_array($pageRecord);

        $canCreateNew = false;
        if ($hasAccess) {
            // Doc header handling
            $moduleTemplate->getDocHeaderComponent()->setMetaInformation($pageRecord);
            $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
            $previewDataAttributes = PreviewUriBuilder::create($pageUid)
                ->withRootLine(BackendUtility::BEgetRootLine($pageUid))
                ->buildDispatcherDataAttributes();
            $viewButton = $buttonBar->makeLinkButton()
                ->setDataAttributes($previewDataAttributes ?? [])
                ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.showPage'))
                ->setIcon($this->iconFactory->getIcon('actions-view-page', enum_exists(IconSize::class) ? IconSize::SMALL : Icon::SIZE_SMALL))
                ->setHref('#');
            $buttonBar->addButton($viewButton);

            $calculatedPermissions = new Permission($backendUser->calcPerms($pageRecord));
            $canCreateNew = $backendUser->isAdmin() || $calculatedPermissions->createPagePermissionIsGranted();
        }

        $hasNewPagesData = false;
        $pagesCreated = false;
        $visiblePages = [];

        if ($canCreateNew) {
            $parsedBody = $request->getParsedBody();
            $newPagesData = $parsedBody['pageTree'] ?? '';
            if (!empty($newPagesData)) {
                $newPagesData = explode("\r\n", $newPagesData);
                $newPagesData = $this->filterComments($newPagesData);

                $hasNewPagesData = true;
                $afterExisting = isset($parsedBody['createInListEnd']);
                $hidePages = isset($parsedBody['hidePages']);
                $hidePagesInMenu = isset($parsedBody['hidePagesInMenus']);
                $pagesCreated = $this->createPagetree($newPagesData, $pageUid, $afterExisting, $hidePages, $hidePagesInMenu);
                $subPages = $this->getSubPagesOfPage($pageUid);
                foreach ($subPages as $page) {
                    $calculatedPermissions = $backendUser->calcPerms($page);
                    if ($calculatedPermissions & Permission::PAGE_SHOW || $backendUser->isAdmin()) {
                        $visiblePages[] = $page;
                    }
                }
            }
        }

        $moduleTemplate->assignMultiple([
            'canCreateNew' => $canCreateNew,
            'maxTitleLength' => $backendUser->uc['titleLen'] ?? 20,
            'pageUid' => $pageUid,
            'hasNewPagesData' => $hasNewPagesData,
            'pagesCreated' => $pagesCreated,
            'visiblePages' => $visiblePages,
            'dataHandlerErrors' => $this->dataHandlerErrors,
        ]);

        return $moduleTemplate->renderResponse('Page/NewPagetree');
    }

    /**
     * Persist new pages in DB
     *
     * @param array $newPagesData Data array with title and page type
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
            try {
                $subPages = $this->getSubPagesOfPage($pageUid);
            } catch (Exception) {
                return false;
            }
            $lastPage = end($subPages);
            if (isset($lastPage['uid']) && MathUtility::canBeInterpretedAsInteger($lastPage['uid'])) {
                $firstPid = -(int)$lastPage['uid'];
            }
        }

        $commandArray = [];

        $ic = $this->getIndentationChar();
        $sc = $this->getSeparationChar();
        $ef = $this->getExtraFields();

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
                        $commandArray['pages']['NEW' . $pageIndex][$field] = $parts[$index] ?? '';
                    }
                }
                $oldLevel = $level;
                $pageIndex++;
            }
        }

        if (!empty($commandArray)) {
            /** @var DataHandler $dataHandler */
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            // Set default TCA values specific for the user
            $backendUser = $this->getBackendUser();
            $tcaDefaultOverride = $backendUser->getTSConfig()['TCAdefaults.'] ?? null;
            if (is_array($tcaDefaultOverride)) {
                $dataHandler->setDefaultsFromUserTS($tcaDefaultOverride);
            }
            $dataHandler->start($commandArray, []);
            $dataHandler->process_datamap();

            if (!empty($dataHandler->errorLog)) {
                $this->dataHandlerErrors = $dataHandler->errorLog;
            } else {
                $pagesCreated = true;
                BackendUtility::setUpdateSignal('updatePageTree');
            }
        }

        return $pagesCreated;
    }

    /**
     * Get a list of sub-pages with some all fields from given page.
     * Fetch all data fields for full page icon display
     *
     * @param int $pageUid Get sub-pages from this pages
     * @return array
     * @throws Exception
     */
    protected function getSubPagesOfPage(int $pageUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
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
     * @param array $data the uncompressed array
     *
     * @return   array      the data as a compressed array
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
     * @param array $data the data array
     * @param int $oldLevel the current level
     * @param string $character indentation character
     *
     * @return   array      the data as a nested array
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
     * @param array $data input array
     *
     * @return   array      the data reversed
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
            $newData[$index]['value'] = $chunk['value'];
            $index++;
        }
        krsort($newData);

        return $newData;
    }

    /**
     * Return the data without comment fields and empty lines
     *
     * @param array $data input array
     *
     * @return   array      the data reversed
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
     * @return   string      the indentation character
     */
    private function getIndentationChar(): string
    {
        $character = $this->request->getParsedBody()['indentationCharacter'];
        return match ($character) {
            'dot' => '\.',
            'tab' => '\t',
            default => ' ',
        };
    }

    /**
     * Get the separation character (, or | or ; or :)
     *
     * @return   string      the separation character
     */
    private function getSeparationChar(): string
    {
        $character = $this->request->getParsedBody()['separationCharacter'] ?? 'comma';
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
     * @return   array      the extra fields
     */
    private function getExtraFields(): array
    {
        $efLine = $this->request->getParsedBody()['extraFields'] ?? '';
        if (trim($efLine)) {
            return GeneralUtility::trimExplode(' ', $efLine, true);
        }

        return [];
    }

    /**
     * Returns LanguageService
     *
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $this->languageServiceFactory->createFromUserPreferences($this->getBackendUser());
    }

    /**
     * Returns current BE user
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'];
        if (!($backendUser instanceof BackendUserAuthentication)) {
            throw new \RuntimeException('No authenticated backend user available', 1737651600);
        }

        return $backendUser;
    }
}
