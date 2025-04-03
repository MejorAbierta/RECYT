<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use APP\i18n\AppLocale;
use APP\facades\Repo;

class CountArticles extends AbstractRunner implements InterfaceRunner
{
    protected $contextId;

    public function run(&$params)
    {
        $fileManager = new \FileManager();
        $context = $params["context"];
        $dirFiles = $params['temporaryFullFilePath'];
        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        try {
            $dateTo = $params['dateTo'] ?? date('Ymd', strtotime("-1 day"));
            $dateFrom = $params['dateFrom'] ?? date("Ymd", strtotime("-1 year", strtotime($dateTo)));
            $params2 = array($this->contextId, $dateFrom, $dateTo);
            $paramsPublished = array(
                $this->contextId,
                date('Y-m-d', strtotime($dateFrom)),
                date('Y-m-d', strtotime($dateTo)),
            );

            $data = "Nº de artículos para la revista " . \Application::getContextDAO()->getById($this->contextId)->getPath();
            $data .= " desde el " . date('d-m-Y', strtotime($dateFrom)) . " hasta el " . date('d-m-Y', strtotime($dateTo)) . "\n";
            $data .= "Recibidos: " . $this->countSubmissionsReceived($params2) . "\n";
            $data .= "Aceptados: " . $this->countSubmissionsAccepted($params2) . "\n";
            $data .= "Rechazados: " . $this->countSubmissionsDeclined($params2) . "\n";
            $data .= "Publicados: " . $this->countSubmissionsPublished($paramsPublished);

            $file = fopen($dirFiles . "/numero_articulos.txt", "w");
            fwrite($file, $data);
            fclose($file);

            $this->generateCsv($this->getSubmissionsReceived($params2), 'recibidos', $dirFiles);
            $this->generateCsv($this->getSubmissionsAccepted($params2), 'aceptados', $dirFiles);
            $this->generateCsv($this->getSubmissionsDeclined($params2), 'rechazados', $dirFiles);
            $this->generateCsv($this->getSubmissionsPublished($paramsPublished), 'publicados', $dirFiles);

            if (!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/countArticles.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: ' . $e->getMessage());
        }
    }

    private function generateCsv($submissions, $type, $dirFiles)
    {
        $file = fopen($dirFiles . "/envios_" . $type . ".csv", "w");
        fputcsv($file, array("ID", "DOI", "Título", "Fecha"));

        foreach ($submissions as $submission) {
            $submissionObj = Repo::submission()->get($submission['submission_id']);
            $publication = $submissionObj->getCurrentPublication();
            $doi = $publication->getStoredPubId('doi') ?? 'N/A';

            fputcsv($file, array(
                $submission['submission_id'],
                $doi,
                $submission['title'],
                $submission['date']
            ));
        }
        fclose($file);
    }

    private function countSubmissionsReceived($params)
    {
        $submissions = $this->getSubmissionsReceived($params);
        return count($submissions);
    }

    private function countSubmissionsAccepted($params)
    {
        $submissions = $this->getSubmissionsAccepted($params);
        return count($submissions);
    }

    private function countSubmissionsDeclined($params)
    {
        $submissions = $this->getSubmissionsDeclined($params);
        return count($submissions);
    }

    private function countSubmissionsPublished($params)
    {
        $submissions = $this->getSubmissionsPublished($params);
        return count($submissions);
    }

    private function getSubmissionsReceived($params)
    {
        $contextId = $params[0];
        $dateFrom = $params[1];
        $dateTo = $params[2];
        $locale = AppLocale::getLocale();

        $submissions = Repo::submission()->getCollector()->filterByContextIds([$contextId])->getMany();


        $filteredSubmissions = [];
        foreach ($submissions as $submission) {
            $dateSubmitted = strtotime($submission->getDateSubmitted());
            if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo)) {
                $publication = $submission->getCurrentPublication();
                $filteredSubmissions[] = [
                    'submission_id' => $submission->getId(),
                    'title' => $publication->getLocalizedData('title', $locale),
                    'date' => $submission->getDateSubmitted()
                ];
            }
        }
        return $filteredSubmissions;
    }

    private function getSubmissionsAccepted($params)
    {
        $contextId = $params[0];
        $dateFrom = $params[1];
        $dateTo = $params[2];
        $locale = \AppLocale::getLocale();

        $submissions = Repo::submission()->getCollector()->filterByContextIds([$contextId])->filterByStatus([STATUS_PUBLISHED])->getMany();


        $filteredSubmissions = [];
        foreach ($submissions as $submission) {
            $dateSubmitted = strtotime($submission->getDateSubmitted());
            if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo)) {
                $publication = $submission->getCurrentPublication();
                $filteredSubmissions[] = [
                    'submission_id' => $submission->getId(),
                    'title' => $publication->getLocalizedData('title', $locale),
                    'date' => $submission->getDateSubmitted()
                ];
            }
        }
        return $filteredSubmissions;
    }

    private function getSubmissionsDeclined($params)
    {
        $contextId = $params[0];
        $dateFrom = $params[1];
        $dateTo = $params[2];
        $locale = \AppLocale::getLocale();

        $submissions = Repo::submission()->getCollector()->filterByContextIds([$contextId])->filterByStatus([STATUS_DECLINED])->getMany();


        $filteredSubmissions = [];
        foreach ($submissions as $submission) {
            $dateSubmitted = strtotime($submission->getDateSubmitted());
            if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo)) {
                $publication = $submission->getCurrentPublication();
                $filteredSubmissions[] = [
                    'submission_id' => $submission->getId(),
                    'title' => $publication->getLocalizedData('title', $locale),
                    'date' => $submission->getDateSubmitted()
                ];
            }
        }
        return $filteredSubmissions;
    }

    private function getSubmissionsPublished($params)
    {
        $contextId = $params[0];
        $dateFrom = $params[1];
        $dateTo = $params[2];
        $locale = \AppLocale::getLocale();

        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus([STATUS_PUBLISHED])->getMany();

        $filteredSubmissions = [];
        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            $datePublished = $publication ? $publication->getData('datePublished') : null;
            if ($datePublished && strtotime($datePublished) >= strtotime($dateFrom) && strtotime($datePublished) <= strtotime($dateTo)) {
                $filteredSubmissions[] = [
                    'submission_id' => $submission->getId(),
                    'title' => $publication->getLocalizedData('title', $locale),
                    'date' => $datePublished
                ];
            }
        }
        return $filteredSubmissions;
    }
}