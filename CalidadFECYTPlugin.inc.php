<?php

require_once(__DIR__ . '/vendor/autoload.php');


use APP\facades\Repo;
use APP\i18n\AppLocale;
use CalidadFECYT\classes\main\CalidadFECYT;
use PKP\plugins\GenericPlugin;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\linkAction\request\AjaxModal;

class CalidadFECYTPlugin extends GenericPlugin
{
    private static $statsMenuItemAdded = false;

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return true;
        }

        $this->addLocaleData();
        if ($success && $this->getEnabled($mainContextId) && !self::$statsMenuItemAdded) {
            $this->addStatsNavigationMenuItem($mainContextId);
            self::$statsMenuItemAdded = true;
        }

        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addJavaScript(
            'calidadfecyt',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/calidadfecyt.js',
            ['contexts' => 'backend']
        );

        $templateMgr->addJavaScript(
            'calidadfecytInit',
            'if (typeof initializeCalidadFECYT === "function") { $(document).on("ajaxModalLoaded", function() { initializeCalidadFECYT(); }); }',
            ['inline' => true]
        );

        return $success;
    }


    public function getName()
    {
        return 'CalidadFECYTPlugin';
    }

    public function getDisplayName()
    {
        return __('plugins.generic.calidadfecyt.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.calidadfecyt.description');
    }

    private function addStatsNavigationMenuItem($contextId)
    {
        $request = $this->getRequest();
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : CONTEXT_ID_NONE;

        $navigationMenuItemDao = DAORegistry::getDAO('NavigationMenuItemDAO');
        $navigationMenuItem = $navigationMenuItemDao->getByPath($contextId, 'fecyt-stats');

        if (!$navigationMenuItem) {
            $statsContentEN = $this->generateStatsContent($context, 'en');
            $statsContentES = $this->generateStatsContent($context, 'es');
            $navigationMenuItem = $navigationMenuItemDao->newDataObject();
            $navigationMenuItem->setPath('fecyt-stats');
            $navigationMenuItem->setType('NMI_TYPE_CUSTOM');
            $navigationMenuItem->setContextId($contextId);
            $navigationMenuItem->setTitle(__('plugins.generic.calidadfecyt.stats.menu'), 'en');
            $navigationMenuItem->setTitle(__('plugins.generic.calidadfecyt.stats.menu'), 'es');
            $navigationMenuItem->setContent($statsContentEN, 'en');
            $navigationMenuItem->setContent($statsContentES, 'es');

            $navigationMenuItemDao->insertObject($navigationMenuItem);



        }
    }

    private function generateStatsContent($context, $locale)
    {
        if (!$context) {
            return "<h2>" . __("plugins.generic.calidadfecyt.stats.header", [], $locale) . "</h2><p>" . __("plugins.generic.calidadfecyt.stats.error.noJournal", [], $locale) . "</p>";
        }

        $contextId = $context->getId();

        try {
            $currentYear = date('Y');
            $lastCompletedYear = $currentYear - 1;
            $summaryDateFrom = date('Ymd', strtotime("$lastCompletedYear-01-01"));
            $summaryDateTo = date('Ymd', strtotime("$lastCompletedYear-12-31"));

            $submissionStats = $this->getSubmissionStats($contextId, $summaryDateFrom, $summaryDateTo);
            $reviewerDetails = $this->getReviewerDetails($contextId, $summaryDateFrom, $summaryDateTo);

            $totalReceived = $submissionStats['received'];
            $totalPublished = $submissionStats['published'];
            $totalDeclined = $submissionStats['declined'];
            $rejectionRate = $totalReceived > 0 ? round(($totalDeclined / $totalReceived) * 100, 1) : 0;
            $journalName = $context->getLocalizedName();
            $totalReviewers = $reviewerDetails['totalReviewers'];
            $foreignReviewers = $reviewerDetails['foreignReviewers'];
            $foreignPercentage = $totalReviewers > 0 ? round(($foreignReviewers / $totalReviewers) * 100, 1) : 0;

            $statsContent = "<h2>" . sprintf(__("plugins.generic.calidadfecyt.stats.header", [], $locale), $lastCompletedYear) . "</h2>";
            $statsContent .= "<p>" . sprintf(
                __("plugins.generic.calidadfecyt.stats.summary", [], $locale),
                $totalReceived,
                $lastCompletedYear,
                $lastCompletedYear,
                $totalPublished,
                $rejectionRate,
                $totalReviewers,
                $foreignPercentage,
                $journalName
            ) . "</p>";
            $statsContent .= "<h3>" . __("plugins.generic.calidadfecyt.stats.reviewers", [], $locale) . "</h3><ul>";

            foreach ($reviewerDetails['reviewers'] as $reviewer) {
                $statsContent .= "<li>" . htmlspecialchars($reviewer['fullName']) .
                    ($reviewer["affiliation"] && $reviewer["affiliation"] !== 'Unknown Affiliation' ? " (" . htmlspecialchars($reviewer["affiliation"]) . ")" : "") .
                    "</li>";
            }
            $statsContent .= "</ul>";

            return $statsContent;
        } catch (\Exception $e) {
            return "<h2>" . __("plugins.generic.calidadfecyt.stats.header", [], $locale) . "</h2><p>" . sprintf(__("plugins.generic.calidadfecyt.stats.error.generic", [], $locale), htmlspecialchars($e->getMessage())) . "</p>";
        }
    }

    private function getSubmissionStats($contextId, $dateFrom, $dateTo)
    {
        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$contextId])
            ->getMany();

        $stats = ['received' => 0, 'accepted' => 0, 'declined' => 0, 'published' => 0];

        foreach ($submissions as $submission) {
            $dateSubmitted = strtotime($submission->getDateSubmitted());
            $publication = $submission->getCurrentPublication();
            $datePublished = $publication ? strtotime($publication->getData('datePublished')) : null;
            $status = $submission->getStatus();

            if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo)) {
                $stats['received']++;
                if ($status == STATUS_PUBLISHED && $datePublished && $datePublished <= strtotime($dateTo)) {
                    $stats['accepted']++;
                    $stats['published']++;
                }
                if ($status == STATUS_DECLINED) {
                    $stats['declined']++;
                }
            }
        }

        return $stats;
    }

    private function getReviewerDetails($contextId, $dateFrom, $dateTo)
    {
        $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
        $reviewersResult = $reviewAssignmentDao->retrieve(
            "SELECT DISTINCT ra.reviewer_id
             FROM review_assignments ra
             JOIN submissions s ON ra.submission_id = s.submission_id
             WHERE s.context_id = ?
             AND ra.date_completed IS NOT NULL
             AND ra.date_completed BETWEEN ? AND ?",
            [$contextId, date('Y-m-d', strtotime($dateFrom)), date('Y-m-d', strtotime($dateTo))]
        );

        $reviewers = [];
        $foreignReviewers = 0;
        $totalReviewers = 0;

        foreach ($reviewersResult as $row) {
            $reviewerId = $row->reviewer_id;
            $user = Repo::user()->get($reviewerId);

            if ($user) {
                $fullName = $user->getFullName();
                $affiliation = $user->getLocalizedAffiliation() ?: 'Unknown Affiliation';
                $country = $user->getCountry() ?: 'Unknown';

                $reviewers[] = [
                    'fullName' => $fullName,
                    'affiliation' => $affiliation
                ];

                if ($country !== 'ES') {
                    $foreignReviewers++;
                }
                $totalReviewers++;
            }
        }

        return [
            'reviewers' => $reviewers,
            'foreignReviewers' => $foreignReviewers,
            'totalReviewers' => $totalReviewers
        ];
    }
    public function getSubmissionsByDateRange($contextId, $dateFrom, $dateTo)
    {
        $locale = AppLocale::getLocale();

        $query = DB::table('submissions as s')
            ->select('s.submission_id', 'pp_title.setting_value as title', 'p.date_published')
            ->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->join('publication_settings as pp_title', 'p.publication_id', '=', 'pp_title.publication_id')
            ->where('s.context_id', '=', $contextId)
            ->where('p.status', '=', 3)
            ->where('pp_title.setting_name', '=', 'title')
            ->where('pp_title.locale', '=', $locale);

        if ($dateFrom) {
            $query->where('p.date_published', '>=', date('Y-m-d', strtotime($dateFrom)));
        }
        if ($dateTo) {
            $query->where('p.date_published', '<=', date('Y-m-d', strtotime($dateTo)));
        }

        $results = $query->get();
        $submissions = [];
        foreach ($results as $row) {
            $title = $row->title;
            $submissions[] = [
                'id' => $row->submission_id,
                'title' => strlen($title) > 80 ? mb_substr($title, 0, 77, 'UTF-8') . '...' : $title,
                'date_published' => $row->date_published,
            ];
        }

        return $submissions;
    }
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, [
                            'verb' => 'settings',
                            'plugin' => $this->getName(),
                            'category' => 'generic'
                        ]),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                )
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    public function manage($args, $request)
    {
        $this->import('classes.main.CalidadFECYT');
        $templateMgr = TemplateManager::getManager($request);
        $context = $request->getContext();
        $router = $request->getRouter();

        switch ($request->getUserVar('verb')) {
            case 'settings':
                $calidadFECYT = new CalidadFECYT(['request' => $request, 'context' => $context]);
                $defaultDateFrom = date('Y-m-d', strtotime('-1 year'));
                $defaultDateTo = date('Y-m-d', strtotime('-1 day'));
                $templateParams = [
                    'journalTitle' => $context->getLocalizedName(),
                    'defaultDateFrom' => $defaultDateFrom,
                    'defaultDateTo' => $defaultDateTo,
                    'baseUrl' => $router->url($request, null, null, 'manage', null, [
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]),
                    'fetchSubmissionsUrl' => $router->url($request, null, null, 'manage', null, [
                        'verb' => 'fetchSubmissions',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]),
                    'noSubmissionsMessage' => __('plugins.generic.calidadfecyt.noSubmissionsFound')
                ];

                $linkActions = [];
                $index = 0;
                foreach ($calidadFECYT->getExportClasses() as $export) {
                    $exportAction = new stdClass();
                    $exportAction->name = $export;
                    $exportAction->index = $index++;
                    $linkActions[] = $exportAction;
                }

                $lastCompletedYear = date('Y') - 1;
                $submissionsDateFrom = date('Y-m-d', strtotime("$lastCompletedYear-01-01"));

                $templateParams['submissions'] = $this->getSubmissionsByDateRange($context->getId(), $submissionsDateFrom, $defaultDateTo);
                $templateParams['exportAllAction'] = true;
                $templateParams['linkActions'] = $linkActions;
                $templateMgr->assign($templateParams);

                $templateMgr->assign('editorialUrl', $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    array()
                ));

                return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('settings_form.tpl')));

            case 'export':
                try {
                    $request->checkCSRF();
                    $params = [
                        'request' => $request,
                        'context' => $context,
                        'dateFrom' => $request->getUserVar('dateFrom') ? date('Ymd', strtotime($request->getUserVar('dateFrom'))) : null,
                        'dateTo' => $request->getUserVar('dateTo') ? date('Ymd', strtotime($request->getUserVar('dateTo'))) : null
                    ];
                    $calidadFECYT = new CalidadFECYT($params);
                    $calidadFECYT->export();
                } catch (Exception $e) {
                    $request->getDispatcher()->handle404();
                }
                return;

            case 'exportAll':
                try {
                    $request->checkCSRF();
                    $params = [
                        'request' => $request,
                        'context' => $context,
                        'dateFrom' => $request->getUserVar('dateFrom') ? date('Ymd', strtotime($request->getUserVar('dateFrom'))) : null,
                        'dateTo' => $request->getUserVar('dateTo') ? date('Ymd', strtotime($request->getUserVar('dateTo'))) : null
                    ];
                    $calidadFECYT = new CalidadFECYT($params);
                    $calidadFECYT->exportAll();
                } catch (Exception $e) {
                    $request->getDispatcher()->handle404();
                }
                return;

            case 'editorial':
                try {
                    $request->checkCSRF();
                    $params = [
                        'request' => $request,
                        'context' => $context,
                        'dateFrom' => $request->getUserVar('dateFrom') ? date('Ymd', strtotime($request->getUserVar('dateFrom'))) : null,
                        'dateTo' => $request->getUserVar('dateTo') ? date('Ymd', strtotime($request->getUserVar('dateTo'))) : null
                    ];
                    $calidadFECYT = new CalidadFECYT($params);
                    $calidadFECYT->editorial($request->getUserVar('submission'));
                } catch (Exception $e) {
                    $request->getDispatcher()->handle404();
                }
                return;

            default:
                $request->getDispatcher()->handle404();
                return;
            case 'fetchSubmissions':
                try {
                    $context = $request->getContext();
                    $dateFrom = $request->getUserVar('dateFrom') ? date('Ymd', strtotime($request->getUserVar('dateFrom'))) : null;
                    $dateTo = $request->getUserVar('dateTo') ? date('Ymd', strtotime($request->getUserVar('dateTo'))) : null;
                    $submissions = $this->getSubmissionsByDateRange($context->getId(), $dateFrom, $dateTo);

                    return new JSONMessage(true, $submissions);
                } catch (Exception $e) {
                    return new JSONMessage(false, htmlspecialchars($e->getMessage()));
                }
                break;
        }

        return parent::manage($args, $request);
    }

    public function getSubmissions($contextId)
    {
        $locale = AppLocale::getLocale();

        $results = DB::table('submissions as s')
            ->select('s.submission_id', 'pp_title.setting_value as title')
            ->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->join('publication_settings as pp_issue', 'p.publication_id', '=', 'pp_issue.publication_id')
            ->where('p.status', '=', 3)
            ->join('publication_settings as pp_title', 'p.publication_id', '=', 'pp_title.publication_id')
            ->joinSub(
                DB::table('issues')
                    ->select('issue_id')
                    ->where('journal_id', $contextId)
                    ->where('published', 1)
                    ->orderBy('date_published', 'desc')
                    ->limit(4),
                'latest_issues',
                function ($join) {
                    $join->on('pp_issue.setting_value', '=', 'latest_issues.issue_id');
                }
            )
            ->where('pp_issue.setting_name', 'issueId')
            ->where('pp_title.setting_name', 'title')
            ->where('pp_title.locale', $locale)
            ->get();


        $submissions = [];
        foreach ($results as $row) {
            $title = $row->title;
            $submissions[] = [
                'id' => $row->submission_id,
                'title' => strlen($title) > 80 ? mb_substr($title, 0, 77, 'UTF-8') . '...' : $title,
            ];
        }

        return $submissions;
    }
}