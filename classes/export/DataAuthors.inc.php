<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use PKP\file\FileManager;
use PKP\core\PKPApplication;
use APP\facades\Repo;
use PKP\submission\PKPSubmission;
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
            fputcsv($file, ["ID envío", "DOI", "ID autor", "Nombre", "Apellidos", "Institución", "País", "Correo electrónico"]);

            $submissions = $this->getSubmissions([$this->contextId, $dateFrom, $dateTo]);

            foreach ($submissions as $submission) {
                $publicationId = $submission->getData('currentPublicationId');
                if ($publicationId) {
                    $publication = Repo::publication()->get($publicationId);
                    $authors = Repo::author()
                        ->getCollector()
                        ->filterByPublicationIds([$publicationId])
                        ->getMany();

                    foreach ($authors as $author) {
                        fputcsv($file, [
                            $submission->getId(),
                            $publication->getStoredPubId('doi') ?? '',
                            $author->getId(),
                            $author->getData('givenName', $locale) ?? '',
                            $author->getData('familyName', $locale) ?? '',
                            $author->getData('affiliation', $locale) ?? '',
                            $author->getCountry() ?? '',
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

    public function getSubmissions(array $params): iterable
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_QUEUED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->where('s.date_submitted', '>=', $params[1])
            ->where('s.date_submitted', '<=', $params[2])
            ->distinct('s.submission_id');

        return $collector->getMany($query);
    }
}