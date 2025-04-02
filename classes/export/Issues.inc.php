<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use PKP\file\FileManager;
use APP\facades\Repo;
use PKP\submission\PKPSubmission;
use APP\i18n\AppLocale;
use CalidadFECYT\classes\utils\LocaleUtils;

class Issues extends AbstractRunner implements InterfaceRunner
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
            foreach ($this->getIssues() as $issueItem) {
                $data = $this->getData($issueItem['id']);
                $submissions = $data['results'];
                $countAuthors = $data['count'];
                $volume = $issueItem['volume'] ? "Vol." . $issueItem['volume'] . " " : '';
                $number = $issueItem['number'] ? "Num." . $issueItem['number'] . " " : '';
                $year = $issueItem['year'] ? "(" . $issueItem['year'] . ")" : '';
                $nameFile = "/" . $volume . $number . $year;
                $file = fopen($dirFiles . $nameFile . ".csv", "w");

                if (!empty($data['results'])) {
                    $columns = ["Sección", "Título", "DOI"];
                    for ($a = 1; $a <= $countAuthors; $a++) {
                        $columns = array_merge($columns, [
                            "Nombre (autor " . $a . ")",
                            "Apellidos (autor " . $a . ")",
                            "Institución (autor " . $a . ")",
                            "Rol (autor " . $a . ")",
                        ]);
                    }
                    fputcsv($file, array_values($columns));

                    foreach ($submissions as $submission) {
                        $results = [$submission['section'], $submission['title'], $submission['doi']];
                        $arrayValues = array_values($submission['authors']);
                        $authorCount = count($arrayValues);
                        for ($a = 0; $a < $authorCount; $a++) {

                            array_push(
                                $results,
                                $arrayValues[$a]['givenName'],
                                $arrayValues[$a]['familyName'],
                                $arrayValues[$a]['affiliation'],
                                $arrayValues[$a]['userGroup']
                            );
                        }
                        fputcsv($file, array_values($results));
                    }
                } else {
                    fputcsv($file, ["Este envío no tiene artículos"]);
                }

                fclose($file);
            }

            if (!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/issues.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: ' . $e->getMessage());
        }
    }

    private function getData($issueId)
    {
        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$this->contextId])
            ->filterByIssueIds([$issueId])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED])
            ->orderBy('seq', 'ASC')
            ->getMany();

        $maxAuthors = 0;
        $results = [];

        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            $authors = Repo::author()
                ->getCollector()
                ->filterByPublicationIds([$publication->getId()])
                ->getMany()
                ->toArray();

            $maxAuthors = max($maxAuthors, count($authors));

            $section = Repo::section()->get($publication->getData('sectionId'));

            $results[] = [
                'title' => $publication->getLocalizedData('title', AppLocale::getLocale()),
                'section' => $section && !$section->getHideTitle() ? $section->getLocalizedTitle() : '',
                'doi' => $publication->getStoredPubId('doi') ?? '',
                'authors' => array_map(function ($author) {
                    $userGroup = Repo::userGroup()->get($author->getData('userGroupId'));
                    return [
                        'givenName' => LocaleUtils::getLocalizedDataWithFallback($author, 'givenName'),
                        'familyName' => LocaleUtils::getLocalizedDataWithFallback($author, 'familyName'),
                        'affiliation' => LocaleUtils::getLocalizedDataWithFallback($author, 'affiliation'),
                        'userGroup' => $userGroup ? $userGroup->getLocalizedName() : '',
                        'country' => $author->getCountry() ?? ''
                    ];
                }, $authors)
            ];

        }

        return [
            "count" => $maxAuthors,
            'results' => $results
        ];
    }

    private function getIssues()
    {
        $issues = Repo::issue()
            ->getCollector()
            ->filterByContextIds([$this->contextId])
            ->filterByPublished(true)
            ->orderBy('datePublished', 'DESC')
            ->limit(4)
            ->getMany();

        return array_map(function ($issue) {
            return [
                'id' => $issue->getId(),
                'volume' => $issue->getVolume(),
                'year' => $issue->getYear(),
                'number' => $issue->getNumber(),
            ];
        }, $issues->values()->toArray());
    }
}