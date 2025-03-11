<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;

class Editorial extends AbstractRunner implements InterfaceRunner {

    private $contextId;
    private $submissionId;

    public function run(&$params)
    {
        $fileManager = new \FileManager();
        $context = $params["context"];
        $submission = $params['submission'];

        if(!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        if(!$submission) {
            throw new \Exception("Envío no encontrado");
        }

        $dirFiles = $params['temporaryFullFilePath'];

        try {
            $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao \ReviewAssignmentDAO */
            \AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION, LOCALE_COMPONENT_PKP_USER, LOCALE_COMPONENT_APP_SUBMISSION);

            $this->generateReviewReport($reviewAssignmentDao, $context, $submission, $dirFiles);
            $this->generateHistoryFile($submission, $dirFiles);
            $this->getReview($submission, $context, $dirFiles);
            $this->getSubmissionsFiles($submission, $fileManager, $dirFiles);

            $zipFilename = $dirFiles.'/flujo_editorial_envio_'.$submission.'.zip';
            ZipUtils::zip([], [$dirFiles], $zipFilename);
            $fileManager->downloadByPath($zipFilename);
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: '.$e->getMessage());
        }
    }

    public function getNameMethod($method)
    {
        switch ($method) {
            case SUBMISSION_REVIEW_METHOD_OPEN:
                return 'Abrir';
            case SUBMISSION_REVIEW_METHOD_BLIND:
                return 'Ciego';
            case SUBMISSION_REVIEW_METHOD_DOUBLEBLIND:
                return 'Doble ciego';
            default:
                return '';
        }
    }

    public function generateReviewReport($reviewAssignmentDao, $context, $submission, $dirFiles)
    {
        list($commentsIterator, $reviewsIterator, $interestsArray) = $this->getReviewReport($context->getId(), $submission);
        $comments = array();
        while ($row = $commentsIterator->next()) {
            if (isset($comments[$row['submission_id']][$row['author_id']])) {
                $comments[$row['submission_id']][$row['author_id']] .= "; " . $row['comments'];
            } else {
                $comments[$row['submission_id']][$row['author_id']] = $row['comments'];
            }
        }

        import('lib.pkp.classes.submission.reviewAssignment.ReviewAssignment');
        $recommendations = array(
            SUBMISSION_REVIEWER_RECOMMENDATION_ACCEPT => 'reviewer.article.decision.accept',
            SUBMISSION_REVIEWER_RECOMMENDATION_PENDING_REVISIONS => 'reviewer.article.decision.pendingRevisions',
            SUBMISSION_REVIEWER_RECOMMENDATION_RESUBMIT_HERE => 'reviewer.article.decision.resubmitHere',
            SUBMISSION_REVIEWER_RECOMMENDATION_RESUBMIT_ELSEWHERE => 'reviewer.article.decision.resubmitElsewhere',
            SUBMISSION_REVIEWER_RECOMMENDATION_DECLINE => 'reviewer.article.decision.decline',
            SUBMISSION_REVIEWER_RECOMMENDATION_SEE_COMMENTS => 'reviewer.article.decision.seeComments'
        );

        $columns = array(
            'stage_id' => __('workflow.stage'),
            'round' => 'Ronda',
            'submission' => 'Título del envío',
            'submission_id' => 'ID del envío',
            'reviewer' => 'Revisor/a',
            'user_given' => __('user.givenName'),
            'user_family' => __('user.familyName'),
            'orcid' => __('user.orcid'),
            'country' => __('common.country'),
            'affiliation' => __('user.affiliation'),
            'email' => __('user.email'),
            'interests' => __('user.interests'),
            'dateassigned' => 'Fecha asignada',
            'datenotified' => 'Fecha notificada',
            'dateconfirmed' => 'Fecha confirmada',
            'datecompleted' => 'Fecha completada',
            'dateacknowledged' => 'Fecha de reconocimiento',
            'unconsidered' => 'Sin considerar',
            'datereminded' => 'Fecha recordatorio',
            'dateresponsedue' => __('reviewer.submission.responseDueDate'),
            'overdueresponse' => 'Días de vencimiento de la respuesta',
            'datedue' => __('reviewer.submission.reviewDueDate'),
            'overdue' => 'Días de vencimiento de la revisión',
            'declined' => __('submissions.declined'),
            'recommendation' => 'Recomendación',
            'comments' => 'Comentarios sobre el envío'
        );

        $reviewFormResponseDao = \DAORegistry::getDAO('ReviewFormResponseDAO');
        $reviewFormElementDao = \DAORegistry::getDAO('ReviewFormElementDAO');

        $file = fopen($dirFiles."/Informes_evaluacion.csv", "w");
        if (fputcsv($file, $columns) === false) die("Error al escribir en el archivo CSV\n");

        while ($row = $reviewsIterator->next()) {
            if (substr($row['dateresponsedue'], 11) === '00:00:00') {
                $row['dateresponsedue'] = substr($row['dateresponsedue'], 0, 11) . '23:59:59';
            }
            if (substr($row['datedue'], 11) === '00:00:00') {
                $row['datedue'] = substr($row['datedue'], 0, 11) . '23:59:59';
            }
            list($overdueResponseDays, $overdueDays) = $this->getOverdueDays($row);
            $row['overdueresponse'] = $overdueResponseDays;
            $row['overdue'] = $overdueDays;

            foreach ($columns as $index => $junk) switch ($index) {
                case 'stage_id':
                    $columns[$index] = __(\WorkflowStageDAO::getTranslationKeyFromId($row[$index]));
                    break;
                case 'declined':
                    $columns[$index] = __($row[$index]?'common.yes':'common.no');
                    break;
                case 'unconsidered':
                    $columns[$index] = __($row[$index]?'common.yes':'');
                    break;
                case 'recommendation':
                    if (isset($recommendations[$row[$index]])) {
                        $columns[$index] = (!isset($row[$index])) ? __('common.none') : __($recommendations[$row[$index]]);
                    } else {
                        $columns[$index] = '';
                    }
                    break;
                case 'comments':
                    $reviewAssignment = $reviewAssignmentDao->getById($row['review_id']);
                    $body = '';

                    if ($reviewAssignment->getDateCompleted() != null && ($reviewFormId = $reviewAssignment->getReviewFormId())) {
                        $reviewId = $reviewAssignment->getId();
                        $reviewFormElements = $reviewFormElementDao->getByReviewFormId($reviewFormId);
                        while ($reviewFormElement = $reviewFormElements->next()) {
                            if (!$reviewFormElement->getIncluded()) continue;
                            $body .= strip_tags($reviewFormElement->getLocalizedQuestion());
                            $reviewFormResponse = $reviewFormResponseDao->getReviewFormResponse($reviewId, $reviewFormElement->getId());
                            if ($reviewFormResponse) {
                                $possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
                                if (in_array($reviewFormElement->getElementType(), array(REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES, REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS))) {
                                    ksort($possibleResponses);
                                    $possibleResponses = array_values($possibleResponses);
                                }
                                if (in_array($reviewFormElement->getElementType(), $reviewFormElement->getMultipleResponsesElementTypes())) {
                                    if ($reviewFormElement->getElementType() == REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
                                        foreach ($reviewFormResponse->getValue() as $value) {
                                            $body .= strip_tags($possibleResponses[$value]);
                                        }
                                    } else {
                                        $body .= strip_tags($possibleResponses[$reviewFormResponse->getValue()]);
                                    }
                                } else {
                                    $body .= strip_tags($reviewFormResponse->getValue());
                                }
                            }
                        }
                    }

                    if (isset($comments[$row['submission_id']][$row['reviewer_id']])) {
                        $columns[$index] = $comments[$row['submission_id']][$row['reviewer_id']];
                    } else {
                        $columns[$index] = $body;
                    }
                    break;
                case 'interests':
                    if (isset($interestsArray[$row['reviewer_id']])) {
                        $columns[$index] = $interestsArray[$row['reviewer_id']];
                    } else {
                        $columns[$index] = '';
                    }
                    break;
                default:
                    $columns[$index] = $row[$index];
            }

            fputcsv($file, $columns);
        }

        fclose($file);
    }

    public function getReviewReport($contextId, $submissions)
    {
        $locale = \AppLocale::getLocale();
        $submissionDao = \DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao \SubmissionDAO */

        import('lib.pkp.classes.db.DBRowIterator');
        $commentsReturner = new \DBRowIterator($submissionDao->retrieve(
            'SELECT	submission_id,
				comments,
				author_id
			FROM	submission_comments
			WHERE	submission_id IN('.$submissions.')
			   AND comment_type = ?',
            array(
                COMMENT_TYPE_PEER_REVIEW
            )
        ));

        $userDao = \DAORegistry::getDAO('UserDAO');
        $site = \Application::get()->getRequest()->getSite();
        $sitePrimaryLocale = $site->getPrimaryLocale();

        $params = array_merge(
            array(
                $locale,
                'title',
                'title',
            ),
            $userDao->getFetchParameters(),
            array(
                'affiliation',
                'affiliation',
                $sitePrimaryLocale,
                'orcid',
                (int) $contextId
            )
        );

        $reviewsReturner = new \DBRowIterator($submissionDao->retrieve(
            'SELECT	r.stage_id AS stage_id,
				r.review_id as review_id,
				r.round AS round,
				COALESCE(asl.setting_value, aspl.setting_value) AS submission,
				a.submission_id AS submission_id,
				u.user_id AS reviewer_id,
				u.username AS reviewer,
				' . $userDao->getFetchColumns() .',
				u.email AS email,
				u.country AS country,
				us.setting_value AS orcid,
				COALESCE(uasl.setting_value, uas.setting_value) AS affiliation,
				r.date_assigned AS dateAssigned,
				r.date_notified AS dateNotified,
				r.date_confirmed AS dateConfirmed,
				r.date_completed AS dateCompleted,
				r.date_acknowledged AS dateAcknowledged,
				r.date_reminded AS dateReminded,
				r.date_due AS dateDue,
				r.date_response_due AS dateResponseDue,
				(r.declined=1) AS declined,
				(r.unconsidered=1) AS unconsidered,
				r.recommendation AS recommendation
			FROM	review_assignments r
				LEFT JOIN submissions a ON r.submission_id = a.submission_id
				LEFT JOIN publications p ON a.current_publication_id = p.publication_id
				LEFT JOIN publication_settings asl ON (p.publication_id = asl.publication_id AND asl.locale = ? AND asl.setting_name = ?)
				LEFT JOIN publication_settings aspl ON (p.publication_id = aspl.publication_id AND aspl.locale = p.locale AND aspl.setting_name = ?)
				LEFT JOIN users u ON (u.user_id = r.reviewer_id)
				' . $userDao->getFetchJoins() .'
				LEFT JOIN user_settings uas ON (u.user_id = uas.user_id AND uas.setting_name = ? AND uas.locale = a.locale)
				LEFT JOIN user_settings uasl ON (u.user_id = uasl.user_id AND uasl.setting_name = ? AND uasl.locale = ?)
				LEFT JOIN user_settings us ON (u.user_id = us.user_id AND us.setting_name = ?)
			WHERE	 a.context_id = ?
			AND r.submission_id IN('.$submissions.')
			ORDER BY submission',
            $params
        ));

        import('lib.pkp.classes.user.InterestManager');
        $interestManager = new \InterestManager();
        $assignedReviewerIds = new \DBRowIterator($submissionDao->retrieve(
            'SELECT	r.reviewer_id
			FROM	review_assignments r
				LEFT JOIN submissions a ON r.submission_id = a.submission_id
			WHERE	 a.context_id = ?
			AND r.submission_id IN('.$submissions.')
			ORDER BY r.reviewer_id',
            array((int) $contextId)
        ));
        $interests = array();
        while ($row = $assignedReviewerIds->next()) {
            if (!array_key_exists($row['reviewer_id'], $interests)) {
                $user = $userDao->getById($row['reviewer_id']);
                $reviewerInterests = $interestManager->getInterestsString($user);
                if (!empty($reviewerInterests))	$interests[$row['reviewer_id']] = $reviewerInterests;
            }
        }

        return array($commentsReturner, $reviewsReturner, $interests);
    }

    public function getOverdueDays($row)
    {
        $responseDueTime = strtotime($row['dateresponsedue']);
        $reviewDueTime = strtotime($row['datedue']);
        $overdueResponseDays = $overdueDays = '';
        if (!$row['dateconfirmed']){ 
            if($responseDueTime < time()) { 
                $datediff = time() - $responseDueTime;
                $overdueResponseDays = round($datediff / (60 * 60 * 24));
            } elseif ($reviewDueTime < time()) { 
                $datediff = time() - $reviewDueTime;
                $overdueDays = round($datediff / (60 * 60 * 24));
            }
        } elseif (!$row['datecompleted']) { 
            if ($reviewDueTime < time()) { 
                $datediff = time() - $reviewDueTime;
                $overdueDays = round($datediff / (60 * 60 * 24));
            }
        }

        return array($overdueResponseDays, $overdueDays);
    }

    public function getReview($submission, $context, $dirFiles)
    {
        $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao \ReviewAssignmentDAO */

        $query = $reviewAssignmentDao->retrieve(
            'SELECT	r.review_id, r.submission_id, round
			FROM review_assignments r
            LEFT JOIN submissions s ON r.submission_id = s.submission_id
			WHERE s.context_id = '.$this->contextId.'
			AND r.submission_id ='.$submission.';'
        )->GetArray();

        $text = "Tipo de revisión de la revista: ".$this->getNameMethod($context->getData('defaultReviewMode'))."\n\n";
        $text .= "Revisión por pares acorde a indicaciones\nEnvío: $submission\n";

        foreach ($query as $row) {
            $reviewAssignment = $reviewAssignmentDao->getById($row['review_id']);
            $text .= "- Revisión ".$row['review_id']." de la ronda ".$row['round'].": ".$this->getNameMethod($reviewAssignment->getReviewMethod()). "\n";
        }

        $file = fopen($dirFiles."/Tipologia_revision.txt", "w");
        fwrite($file, $text);
        fclose($file);
    }

    public function generateHistoryFile($submissionId, $dirFiles)
    {
        $entries = array_merge($this->getEventLog($submissionId), $this->getEmailLog($submissionId));

        usort($entries, function($a, $b) {
            if ($a['date'] == $b['date']) return 0;
            return $a['date'] < $b['date'] ? 1 : -1;
        });

        $file = fopen($dirFiles."/Historial.csv", "w");
        $userDao = \DAORegistry::getDAO('UserDAO'); /* @var $userDao \UserDAO */
        $eventLogDao = \DAORegistry::getDAO('SubmissionEventLogDAO'); /* @var $submissionEventLogDao \SubmissionEventLogDAO */

        foreach ($entries as $entry) {
            if ($entry['message']) {
                $eventLog = $eventLogDao->getById($entry['id']);
                $eventParams = $eventLog->getParams();

                fputcsv($file, array(
                    $entry['id'],
                    $userDao->getUserFullName($entry['user_id']),
                    date("Y-m-d", strtotime($entry['date'])),
                    __($eventLog->getMessage(), array(
                        'authorName' => $eventParams['authorName'],
                        'editorName' => $eventParams['editorName'],
                        'submissionId' => $eventParams['submissionId'],
                        'decision' => $eventParams['decision'],
                        'round' => $eventParams['round'],
                        'reviewerName' => $eventParams['reviewerName'],
                        'fileId' => $eventParams['fileId'],
                        'username' => $eventParams['username'],
                        'name' => $eventParams['name'],
                        'originalFileName' => $eventParams['originalFileName'],
                        'title' => $eventParams['title'],
                        'userGroupName' => $eventParams['userGroupName'],
                        'fileRevision' => $eventParams['fileRevision'],
                        'userName' => $eventParams['userName'],
                    )),
                ));
            } else {
                fputcsv($file, array(
                    $entry['id'],
                    $userDao->getUserFullName($entry['sender_id']),
                    date("Y-m-d", strtotime($entry['date'])),
                    __('submission.event.subjectPrefix') . ' ' . $entry['subject'],
                    strip_tags($entry['body']),
                ));
            }
        }

        fclose($file);
    }

    public function getEventLog($submission)
    {
        $eventLogDao = \DAORegistry::getDAO('SubmissionEventLogDAO'); /* @var $eventLogDao \SubmissionEventLogDAO */
        return $eventLogDao->retrieve(
            "SELECT log_id as id, assoc_type as type, date_logged as date, message, user_id
            FROM event_log el
            WHERE assoc_id = ".$submission.";"
        )->GetRows();
    }

    public function getEmailLog($submission)
    {
        $submissionEmailLogDao = \DAORegistry::getDAO('SubmissionEmailLogDAO'); /* @var $submissionEmailLogDao \SubmissionEmailLogDAO */

        return $submissionEmailLogDao->retrieve(
            "SELECT log_id as id, assoc_type as type, date_sent as date, subject, sender_id, body
            FROM email_log el
            WHERE assoc_id = ".$submission.";"
        )->GetRows();
    }

    public function getSubmissionsFiles($submission, $fileManager, $dirFiles)
    {
        $submissionFileDao = \DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao \SubmissionFileDAO */
        $submissionFileSubmission = $submissionFileDao->getBySubmissionId($submission);

        if ($submissionFileSubmission) {
            $mainFolder = $dirFiles.'/Archivos';
            if(!$fileManager->fileExists($mainFolder)) $fileManager->mkdirtree($mainFolder);
            $listId = "";

            foreach ($submissionFileSubmission as $submissionFile) {
                $id = $submissionFile->getId();
                $path = $submissionFile->getFilePath();
                $folder = $mainFolder.'/';

                switch ($submissionFile->getFileStage()) {
                    case SUBMISSION_FILE_SUBMISSION: $folder .= 'submission'; break;
                    case SUBMISSION_FILE_REVIEW_FILE: $folder .= 'submission/review'; break;
                    case SUBMISSION_FILE_REVIEW_ATTACHMENT: $folder .= 'submission/review/attachment'; break;
                    case SUBMISSION_FILE_REVIEW_REVISION: $folder .= 'submission/review/revision'; break;
                    case SUBMISSION_FILE_FINAL: $folder .= 'submission/final'; break;
                    case SUBMISSION_FILE_FAIR_COPY: $folder .= 'submission/fairCopy'; break;
                    case SUBMISSION_FILE_EDITOR: $folder .= 'submission/editor'; break;
                    case SUBMISSION_FILE_COPYEDIT: $folder .= 'submission/copyedit'; break;
                    case SUBMISSION_FILE_DEPENDENT: $folder .= 'submission/proof'; break;
                    case SUBMISSION_FILE_PROOF: $folder .= 'submission/proof'; break;
                    case SUBMISSION_FILE_PRODUCTION_READY: $folder .= 'submission/productionReady'; break;
                    case SUBMISSION_FILE_ATTACHMENT: $folder .= 'attachment'; break;
                    case SUBMISSION_FILE_QUERY: $folder .= 'submission/query'; break;
                }

                if(file_exists($path)) {
                    $listId .= $id . "\n";

                    if(!$fileManager->fileExists($folder)) $fileManager->mkdirtree($folder);
                    copy($path, $folder.'/'.$id.'_'.$submissionFile->getOriginalFileName());
                } else {
                    $listId .= $id . "\t Archivo no encontrado\n";
                }
            }

            $file = fopen($mainFolder.'/ID_archivos.txt', "w");
            fwrite($file, $listId);
            fclose($file);
        }
    }

    public function getDeclinedSubmissions($issues)
    {
        $submissionDao = \DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao \SubmissionDAO */
        $query = $submissionDao->retrieve(
            "SELECT s.submission_id
            FROM submissions s
            INNER JOIN publications p ON p.publication_id = s.current_publication_id
            INNER JOIN publication_settings pp on p.publication_id = pp.publication_id
            WHERE s.status=".STATUS_DECLINED."
              AND pp.setting_name='issueId'
              AND pp.setting_value IN (".$issues.")"
        )->GetArray();

        $submissions = array();
        foreach ($query as $row) {
            $submissions[] = $row['submission_id'];
        }

        return $submissions[array_rand($submissions)];
    }
}