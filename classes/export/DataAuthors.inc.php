<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use CalidadFECYT\classes\utils\LocaleUtils;
use PKP\file\FileManager;
use APP\facades\Repo;
use APP\i18n\AppLocale;

class DataAuthors extends AbstractRunner implements InterfaceRunner
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

            $file = fopen($dirFiles . "/autores_" . $dateFrom . "_" . $dateTo . ".csv", "w");
            fputcsv($file, ["ID envío", "DOI", "ID autor", "Nombre", "Apellidos", "Institución", "País", "Filiación extranjera", "Correo electrónico"]);

            $submissions = $this->getSubmissions($dateFrom, $dateTo);

            foreach ($submissions as $submission) {
                $publicationId = $submission->getData('currentPublicationId');
                if ($publicationId) {
                    $publication = Repo::publication()->get($publicationId);
                    $authors = Repo::author()
                        ->getCollector()
                        ->filterByPublicationIds([$publicationId])
                        ->getMany();

                    foreach ($authors as $author) {

                        $isForeign = $author->getData('country') ? ($author->getData('country') !== 'ES' ? 'Sí' : 'No') : '';
                        fputcsv($file, [
                            $submission->getId(),
                            $publication->getStoredPubId('doi') ?? '',
                            $author->getId(),
                            LocaleUtils::getLocalizedDataWithFallback($author, 'givenName', $locale),
                            LocaleUtils::getLocalizedDataWithFallback($author, 'familyName', $locale),
                            LocaleUtils::getLocalizedDataWithFallback($author, 'affiliation', $locale),
                            $author->getCountry() ?? '',
                            $isForeign,
                            $author->getData('email') ?? '',
                        ]);
                    }
                }
            }

            fclose($file);

            if (!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/dataAuthors.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: ' . $e->getMessage());
        }
    }


    private function getSubmissions($dateFrom, $dateTo)
    {
        $submissionRepo = Repo::submission();

        $collector = $submissionRepo->getCollector()
            ->filterByContextIds([$this->contextId]);

        $submissions = $collector->getMany();
        $filteredSubmissions = [];

        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            if ($publication) {
                $datePublished = strtotime($publication->getData('datePublished'));
                if ($datePublished >= strtotime($dateFrom) && $datePublished <= strtotime($dateTo)) {
                    $filteredSubmissions[] = $submission;
                }
            }
        }

        return $filteredSubmissions;

    }
}