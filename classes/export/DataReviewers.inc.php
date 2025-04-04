<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use CalidadFECYT\classes\utils\LocaleUtils;
use PKP\file\FileManager;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
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
            fputcsv($file, ["ID", "Nombre", "Apellidos", "Institución", "País", "Filiación extranjera", "Correo electrónico"]);

            $reviewers = $this->getReviewers([$dateFrom, $dateTo, $this->contextId]);

            foreach ($reviewers as $reviewer) {
                $affiliation = LocaleUtils::getLocalizedDataWithFallback($reviewer, 'affiliation', $locale);

                if (is_string($affiliation) && json_decode($affiliation, true) !== null) {
                    $affiliationData = json_decode($affiliation, true);
                    $affiliation = $affiliationData[$locale] ?? ($affiliationData['en_US'] ?? reset($affiliationData));
                }
                $isForeign = $reviewer->getCountry() ? ($reviewer->getCountry() !== 'ES' ? 'Sí' : 'No') : '';


                fputcsv($file, [
                    $reviewer->getId(),
                    LocaleUtils::getLocalizedDataWithFallback($reviewer, 'givenName', $locale),
                    LocaleUtils::getLocalizedDataWithFallback($reviewer, 'familyName', $locale),
                    $affiliation,
                    $reviewer->getCountry() ?? '',
                    $isForeign,
                    $reviewer->getEmail() ?? '',

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

        $result = DB::table('review_assignments as ra')
            ->leftJoin('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->leftJoin('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->where('s.context_id', $contextId)
            ->whereNotNull('p.date_published')
            ->whereBetween('p.date_published', [
                date('Y-m-d', strtotime($dateFrom)),
                date('Y-m-d', strtotime($dateTo))
            ])
            ->distinct()
            ->pluck('ra.reviewer_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->unique()
            ->values()
            ->all();

        if (empty($result)) {
            return new \ArrayIterator([]);
        }

        $userRepo = Repo::user();
        $collector = $userRepo->getCollector()
            ->filterByUserIds($result);

        return $collector->getMany();
    }
}