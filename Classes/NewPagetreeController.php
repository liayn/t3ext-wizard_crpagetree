<?php
declare(strict_types=1);
namespace MichielRoos\WizardCrpagetree;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Tree\View\BrowseTreeView;
use TYPO3\CMS\Backend\Tree\View\ElementBrowserPageTreeView;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Recordlist\Browser\DatabaseBrowser;

/**
 * "New page tree" controller
 *
 * Fluid template based backend module for TYPO3 9.5
 *
 */
class NewPagetreeController
{
    /**
     * @var ServerRequestInterface
     */
    protected $request;

    /**
     * ModuleTemplate object
     *
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    public function __construct(ModuleTemplate $moduleTemplate = null)
    {
        $this->moduleTemplate = $moduleTemplate ?? GeneralUtility::makeInstance(ModuleTemplate::class);
    }

    /**
     * Main function Handling input variables and rendering main view
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface Response
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        $backendUser = $this->getBackendUser();
        $pageUid = (int)$request->getQueryParams()['id'];

        // Show only if there is a valid page and if this page may be viewed by the user
        $pageRecord = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (!is_array($pageRecord)) {
            // User has no permission on parent page, should not happen, just render an empty page
            $this->moduleTemplate->setContent('');
            return new HtmlResponse($this->moduleTemplate->renderContent());
        }

        // Doc header handling
        /** @var IconFactory $iconFactory */
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->moduleTemplate->getDocHeaderComponent()->setMetaInformation($pageRecord);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $cshButton = $buttonBar->makeHelpButton()
            ->setModuleName('pagetree_new')
            ->setFieldName('pagetree_new');
        $viewButton = $buttonBar->makeLinkButton()
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.showPage'))
            ->setIcon($iconFactory->getIcon('actions-view-page', Icon::SIZE_SMALL))
            ->setHref('#');

        $isV11 = class_exists(Typo3Version::class)
             && GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() >= 11;
        if ($isV11) {
            $previewDataAttributes = \TYPO3\CMS\Backend\Routing\PreviewUriBuilder::create($pageUid)
                ->withRootLine(BackendUtility::BEgetRootLine($pageUid))
                ->buildDispatcherDataAttributes();
            $viewButton->setDataAttributes($previewDataAttributes ?? []);
        } else {
            // @deprecated Using inline JavaScript is deprecated since TYPO3 v11.5 and will be removed in TYPO3 v12.0
            $viewButton->setOnClick(
                BackendUtility::viewOnClick($pageUid, '', BackendUtility::BEgetRootLine($pageUid))
            );
        }
        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setModuleName('pagetree_new')
            ->setGetVariables(['id']);
        $buttonBar->addButton($cshButton)->addButton($viewButton)->addButton($shortcutButton);

        // Main view setup
        /** @var StandaloneView $view */
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName(
            'EXT:wizard_crpagetree/Resources/Private/Templates/Page/NewPagetree.html'
        ));

        $calculatedPermissions = $backendUser->calcPerms($pageRecord);
        $canCreateNew = $backendUser->isAdmin() || $calculatedPermissions & Permission::PAGE_NEW;

        $view->assign('canCreateNew', $canCreateNew);
        $view->assign('maxTitleLength', $backendUser->uc['titleLen'] ?? 20);
        $view->assign('pageUid', $pageUid);

        if ($canCreateNew) {
            $newPagesData = $request->getParsedBody()['pageTree'];
            if (!empty($newPagesData)) {
                $newPagesData = explode("\r\n", $newPagesData);
                $newPagesData = $this->filterComments($newPagesData);

                $hasNewPagesData = true;
                $afterExisting = isset($request->getParsedBody()['createInListEnd']);
                $hidePages = isset($request->getParsedBody()['hidePages']);
                $hidePagesInMenu = isset($request->getParsedBody()['hidePagesInMenus']);
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

            // Display result:
            if ($isV11) {
                $tree = GeneralUtility::makeInstance(ElementBrowserPageTreeView::class);
                $tree->init(' AND pages.doktype < ' . PageRepository::DOKTYPE_RECYCLER . ' AND pages.hidden = "0"');
                $tree->setLinkParameterProvider(GeneralUtility::makeInstance(DatabaseBrowser::class));
                $tree->thisScript = '#';
            } else {
                /** @var BrowseTreeView $tree */
                $tree = GeneralUtility::makeInstance(BrowseTreeView::class);
                $tree->init(' AND pages.doktype < 199 AND pages.hidden = "0"');
                $tree->thisScript = '#';
                $tree->ext_IconMode = true;
                $tree->expandAll = true;
            }

            $tree->getTree($pageUid);

            $view->assign('createdPages', $tree->printTree());

            $view->assign('hasNewPagesData', $hasNewPagesData);
        }

        $this->moduleTemplate->setContent($view->render());
        return new HtmlResponse($this->moduleTemplate->renderContent());
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

        // Set first pid to "-1 * uid of last existing sub page" if pages should be created at end
        $firstPid = $pageUid;
        if ($afterExisting) {
            $subPages = $this->getSubPagesOfPage($pageUid);
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
                        $commandArray['pages']['NEW' . $pageIndex][$field] = $parts[$index];
                    }
                }
                $oldLevel = $level;
                $pageIndex++;
            }
        }

        if (!empty($commandArray)) {
            $pagesCreated = true;
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
            BackendUtility::setUpdateSignal('updatePageTree');
        }

        return $pagesCreated;
    }

    /**
     * Get a list of sub pages with some all fields from given page.
     * Fetch all data fields for full page icon display
     *
     * @param int $pageUid Get sub pages from this pages
     * @return array
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
                    $queryBuilder->createNamedParameter($pageUid, PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    $GLOBALS['TCA']['pages']['ctrl']['languageField'],
                    $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)
                )
            )
            ->orderBy('sorting')
            ->execute()
            ->fetchAll();
    }


    /**
     * Return the data as a compressed array
     *
     * @param array $data : the uncompressed array
     *
     * @return   array      the data as a compressed array
     */
    private function compressArray(array $data): array
    {
        $newData = [];
        foreach ($data as $value) {
            if ($value['value']) {
                $newData[] = $value['value'];
            }
            if ($value['data']) {
                $newData = array_merge($newData, $this->compressArray($value['data']));
            }
        }

        return $newData;
    }

    /**
     * Return the data as a nested array
     *
     * @param array $data : the data array
     * @param int $oldLevel : the current level
     * @param string $character : indentation character
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
                     * We have entered a sub level. Find the chunk of the array that
                     * constitues this sub level. Pass this chunk to the getArray
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
     * @param array $data : input array
     *
     * @return   array      the data reversed
     */
    private function reverseArray(array $data): array
    {
        $newData = [];
        $index = 0;
        foreach ($data as $chunk) {
            if (is_array($chunk['data'])) {
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
     * @param array $data : input array
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
            if (preg_match('#^//#', ltrim($value)) || preg_match('/^#/', ltrim($value))
            ) {
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
        switch ($character) {
            case 'dot':
                $character = '\.';
                break;
            case 'tab':
                $character = '\t';
                break;
            case 'space':
            default:
                $character = ' ';
                break;
        }

        return $character;
    }

    /**
     * Get the separation character (, or | or ; or :)
     *
     * @return   string      the separation character
     */
    private function getSeparationChar(): string
    {
        $character = $this->request->getParsedBody()['separationCharacter'];
        switch ($character) {
            case 'pipe':
                $character = '|';
                break;
            case 'semicolon':
                $character = ';';
                break;
            case 'colon':
                $character = ':';
                break;
            case 'comma':
            default:
                $character = ',';
                break;
        }

        return $character;
    }

    /**
     * Get the extra fields
     *
     * @return   array      the extra fields
     */
    private function getExtraFields(): array
    {
        $efLine = $this->request->getParsedBody()['extraFields'];
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
        return $GLOBALS['LANG'];
    }

    /**
     * Returns current BE user
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
