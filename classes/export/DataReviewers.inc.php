<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use CalidadFECYT\classes\utils\LocaleUtils;
use PKP\file\FileManager;
use APP\facades\Repo;
use APP\i18n\AppLocale;

class DataReviewers extends AbstractRunner implements InterfaceRunner
{
    private int $contextId;

    public function run(&$params)
    {
        $fileManager = new FileManager();
        $context = $params["context"] ?? null;
        $dirFiles = $params['temporaryFullFilePath'] ?? '';

        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }

        $this->contextId = $context->getId();

        try {
            $dateTo = $params['dateTo'] ?? date('Ymd', strtotime("-1 day"));
            $dateFrom = $params['dateFrom'] ?? date("Ymd", strtotime("-1 year", strtotime($dateTo)));
            $locale = AppLocale::getLocale();

            $file = fopen($dirFiles . "/revisores_" . $dateFrom . "_" . $dateTo . ".csv", "w");
            fputcsv($file, ["ID", "Nombre", "Apellidos", "Institución", "País", "Correo electrónico"]);

            $reviewers = $this->getReviewers([$dateFrom, $dateTo, $this->contextId]);

            foreach ($reviewers as $reviewer) {
                $affiliation = LocaleUtils::getLocalizedDataWithFallback($reviewer, 'affiliation', $locale);

                if (is_string($affiliation) && json_decode($affiliation, true) !== null) {
                    $affiliationData = json_decode($affiliation, true);
                    $affiliation = $affiliationData[$locale] ?? ($affiliationData['en_US'] ?? reset($affiliationData));
                }

                fputcsv($file, [
                    $reviewer->getId(),
                    LocaleUtils::getLocalizedDataWithFallback($reviewer, 'givenName', $locale),
                    LocaleUtils::getLocalizedDataWithFallback($reviewer, 'familyName', $locale),
                    $affiliation,
                    $reviewer->getCountry() ?? '',
                    $reviewer->getEmail() ?? '' // Cambiado de getData('email') a getEmail()
                ]);
            }

            fclose($file);

            if (!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/dataReviewers.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: ' . $e->getMessage());
        }
    }

    private function getReviewers($params)
    {
        [$dateFrom, $dateTo, $contextId] = $params;

        $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO');
        $result = $reviewAssignmentDao->retrieve(
            "SELECT DISTINCT ra.reviewer_id
         FROM review_assignments ra
         LEFT JOIN submissions s ON (s.submission_id = ra.submission_id)
         WHERE s.context_id = ?
         AND ra.date_completed IS NOT NULL
         AND ra.date_completed >= ?
         AND ra.date_completed <= ?",
            [$contextId, $dateFrom, $dateTo]
        );

        $reviewerIds = [];
        foreach ($result as $row) {
            $reviewerIds[] = (int) $row->reviewer_id;
        }
        $reviewerIds = array_unique($reviewerIds);

        if (empty($reviewerIds)) {
            return new \ArrayIterator([]);
        }

        $userRepo = Repo::user();


        $collector = $userRepo->getCollector()
            ->filterByUserIds($reviewerIds);

        $users = $collector->getMany();
        return $users;
    }
}