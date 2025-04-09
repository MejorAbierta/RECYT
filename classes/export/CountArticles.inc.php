<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use Illuminate\Support\Facades\DB;
use PKP\file\FileManager;
use APP\facades\Repo;

class CountArticles extends AbstractRunner implements InterfaceRunner
{
    private $contextId;

    public function run(&$params)
    {
        $fileManager = new FileManager();
        $context = $params["context"];
        $dirFiles = $params['temporaryFullFilePath'];

        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }

        $this->contextId = $context->getId();

        try {
          
            if (!isset($params['dateFrom']) || !isset($params['dateTo'])) {
                throw new \Exception("Date range parameters are required.");
            }
            $dateFrom = $params['dateFrom'];
            $dateTo = $params['dateTo'];

            $params2 = [$this->contextId, $dateFrom, $dateTo];
            $paramsPublished = [
                $this->contextId,
                date('Y-m-d', strtotime($dateFrom)),
                date('Y-m-d', strtotime($dateTo)),
            ];

            $contextDao = \APP\core\Application::getContextDAO();
            $data = "Nº de artículos para la revista " . $contextDao->getById($this->contextId)->getPath();

            $data .= " desde el " . date('d-m-Y', strtotime($dateFrom)) . " hasta el " . date('d-m-Y', strtotime($dateTo)) . "\n";
            $data .= "Recibidos: " . $this->countSubmissionsReceived($params2) . "\n";
            $data .= "Aceptados: " . $this->countSubmissionsAccepted($params2) . "\n";
            $data .= "Rechazados: " . $this->countSubmissionsDeclined($params2) . "\n";
            $data .= "Publicados: " . $this->countSubmissionsPublished($paramsPublished);

            $filePath = $dirFiles . "/numero_articulos.txt";
            file_put_contents($filePath, $data);

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

    public function generateCsv($results, $key, $dirFiles)
    {
        if ($results && count($results) > 0) {
            $file = fopen($dirFiles . "/envios_" . $key . ".csv", "w");
            fputcsv($file, ["ID", "Fecha", "Título"]);

            foreach ($results as $row) {
                $publication = Repo::publication()->get($row->pub);
                $title = $publication ? $publication->getLocalizedFullTitle(null, 'text') : '';

                fputcsv($file, [
                    $row->id,
                    date("Y-m-d", strtotime($row->date)),
                    $title,
                ]);
            }
            fclose($file);

        }
        fclose($file);
    }

    public function countSubmissionsReceived($params)
    {
        return DB::table('submissions as s')
            ->leftJoin('publications as p', function ($join) {
                $join->on('p.publication_id', '=', DB::raw("(SELECT p2.publication_id 
                    FROM publications as p2 
                    WHERE p2.submission_id = s.submission_id 
                    AND p2.status = " . STATUS_PUBLISHED . " 
                    ORDER BY p2.date_published ASC 
                    LIMIT 1)"));
            })
            ->where('s.context_id', $params[0])
            ->where('s.submission_progress', 0)
            ->where(function ($query) {
                $query->whereNull('p.date_published')
                    ->orWhereColumn('s.date_submitted', '<', 'p.date_published');
            })
            ->whereBetween('s.date_submitted', [$params[1], $params[2]])
            ->distinct()
            ->count('s.submission_id');
    }

    public function getSubmissionsReceived($params)
    {
        return DB::table('submissions as s')
            ->select('s.submission_id as id', 's.date_submitted as date', 's.current_publication_id as pub')
            ->leftJoin('publications as p', function ($join) {
                $join->on('p.publication_id', '=', DB::raw("(SELECT p2.publication_id 
                    FROM publications as p2 
                    WHERE p2.submission_id = s.submission_id 
                    AND p2.status = " . STATUS_PUBLISHED . " 
                    ORDER BY p2.date_published ASC 
                    LIMIT 1)"));
            })
            ->where('s.context_id', $params[0])
            ->where('s.submission_progress', 0)
            ->where(function ($query) {
                $query->whereNull('p.date_published')
                    ->orWhereColumn('s.date_submitted', '<', 'p.date_published');
            })
            ->whereBetween('s.date_submitted', [$params[1], $params[2]])
            ->distinct()
            ->get();
    }

    public function countSubmissionsAccepted($params)
    {
        return DB::table('submissions as s')
            ->leftJoin('publications as p', function ($join) {
                $join->on('p.publication_id', '=', DB::raw("(SELECT p2.publication_id 
                    FROM publications as p2 
                    WHERE p2.submission_id = s.submission_id 
                    AND p2.status != " . STATUS_PUBLISHED . " 
                    ORDER BY p2.date_published ASC 
                    LIMIT 1)"));
            })
            ->leftJoin('edit_decisions as ed', 's.submission_id', '=', 'ed.submission_id')
            ->where('s.context_id', $params[0])
            ->where('s.submission_progress', 0)
            ->where(function ($query) {
                $query->whereNull('p.date_published')
                    ->orWhereColumn('s.date_submitted', '<', 'p.date_published');
            })
            ->where('s.status', '!=', STATUS_DECLINED)
            ->where('ed.decision', 1)
            ->whereBetween('ed.date_decided', [$params[1], $params[2]])
            ->distinct()
            ->count('s.submission_id');
    }

    public function getSubmissionsAccepted($params)
    {
        return DB::table('submissions as s')
            ->select('s.submission_id as id', 'ed.date_decided as date', 's.current_publication_id as pub')
            ->leftJoin('edit_decisions as ed', 's.submission_id', '=', 'ed.submission_id')
            ->leftJoin('publications as p', function ($join) {
                $join->on('p.publication_id', '=', DB::raw("(SELECT p2.publication_id 
                    FROM publications as p2 
                    WHERE p2.submission_id = s.submission_id 
                    AND p2.status != " . STATUS_PUBLISHED . " 
                    ORDER BY p2.date_published ASC 
                    LIMIT 1)"));
            })
            ->where('s.context_id', $params[0])
            ->where('s.submission_progress', 0)
            ->where(function ($query) {
                $query->whereNull('p.date_published')
                    ->orWhereColumn('s.date_submitted', '<', 'p.date_published');
            })
            ->where('s.status', '!=', STATUS_DECLINED)
            ->where('ed.decision', 1)
            ->whereBetween('ed.date_decided', [$params[1], $params[2]])
            ->distinct()
            ->get();
    }

    public function countSubmissionsDeclined($params)
    {
        return DB::table('submissions as s')
            ->leftJoin('publications as p', function ($join) {
                $join->on('p.publication_id', '=', DB::raw("(SELECT p2.publication_id 
                    FROM publications as p2 
                    WHERE p2.submission_id = s.submission_id 
                    AND p2.status = " . STATUS_PUBLISHED . " 
                    ORDER BY p2.date_published ASC 
                    LIMIT 1)"));
            })
            ->leftJoin('edit_decisions as ed', 's.submission_id', '=', 'ed.submission_id')
            ->where('s.context_id', $params[0])
            ->where('s.submission_progress', 0)
            ->where(function ($query) {
                $query->whereNull('p.date_published')
                    ->orWhereColumn('s.date_submitted', '<', 'p.date_published');
            })
            ->where('s.status', STATUS_DECLINED)
            ->whereIn('ed.decision', [4, 9])
            ->whereBetween('ed.date_decided', [$params[1], $params[2]])
            ->distinct()
            ->count('s.submission_id');
    }

    public function getSubmissionsDeclined($params)
    {
        return DB::table('submissions as s')
            ->select('s.submission_id as id', 'ed.date_decided as date', 's.current_publication_id as pub')
            ->leftJoin('publications as p', function ($join) {
                $join->on('p.publication_id', '=', DB::raw("(SELECT p2.publication_id 
                    FROM publications as p2 
                    WHERE p2.submission_id = s.submission_id 
                    AND p2.status = " . STATUS_PUBLISHED . " 
                    ORDER BY p2.date_published ASC 
                    LIMIT 1)"));
            })
            ->leftJoin('edit_decisions as ed', 's.submission_id', '=', 'ed.submission_id')
            ->where('s.context_id', $params[0])
            ->where('s.submission_progress', 0)
            ->where(function ($query) {
                $query->whereNull('p.date_published')
                    ->orWhereColumn('s.date_submitted', '<', 'p.date_published');
            })
            ->where('s.status', STATUS_DECLINED)
            ->whereIn('ed.decision', [4, 9])
            ->whereBetween('ed.date_decided', [$params[1], $params[2]])
            ->distinct()
            ->get();
    }

    public function countSubmissionsPublished($params)
    {
        return DB::table('submissions as s')
            ->leftJoin('publications as p', function ($join) {
                $join->on('p.publication_id', '=', DB::raw("(SELECT p2.publication_id 
                    FROM publications as p2 
                    WHERE p2.submission_id = s.submission_id 
                    AND p2.status = " . STATUS_PUBLISHED . " 
                    ORDER BY p2.date_published ASC 
                    LIMIT 1)"));
            })
            ->leftJoin('edit_decisions as ed', 's.submission_id', '=', 'ed.submission_id')
            ->where('s.context_id', $params[0])
            ->where('s.submission_progress', 0)
            ->where(function ($query) {
                $query->whereNull('p.date_published')
                    ->orWhereColumn('s.date_submitted', '<', 'p.date_published');
            })
            ->where('s.status', STATUS_PUBLISHED)
            ->whereBetween('p.date_published', [$params[1], $params[2]])
            ->distinct()
            ->count('s.submission_id');
    }

    public function getSubmissionsPublished($params)
    {
        return DB::table('submissions as s')
            ->select('s.submission_id as id', 'p.date_published as date', 's.current_publication_id as pub')
            ->leftJoin('publications as p', function ($join) {
                $join->on('p.publication_id', '=', DB::raw("(SELECT p2.publication_id 
                    FROM publications as p2 
                    WHERE p2.submission_id = s.submission_id 
                    AND p2.status = " . STATUS_PUBLISHED . " 
                    LIMIT 1)"));
            })
            ->leftJoin('edit_decisions as ed', 's.submission_id', '=', 'ed.submission_id')
            ->where('s.context_id', $params[0])
            ->where('s.submission_progress', 0)
            ->where(function ($query) {
                $query->whereNull('p.date_published')
                    ->orWhereColumn('s.date_submitted', '<', 'p.date_published');
            })
            ->where('s.status', STATUS_PUBLISHED)
            ->whereBetween('p.date_published', [$params[1], $params[2]])
            ->distinct()
            ->get();

    }
}