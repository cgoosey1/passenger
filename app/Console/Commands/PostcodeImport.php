<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPostcodeCSV;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use \App\Models\PostcodeImport as PostcodeImportModel;
use Illuminate\Support\Str;

class PostcodeImport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postcode:import
        {--use-previous : Skip downloading new postcode zip and use the previously stored postcode dump}
        {--force : Force a new download and importing of postcodes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check to see if there is any new postcode data and import it if there is.';

    const ZIP_FILENAME = 'postcodes.zip';

    /**
     * Execute the console command.
     */
    public function handle() : void
    {
        $usePrevious = $this->option('use-previous');
        $force = $this->option('force');
        $imported = false;

        if (!$usePrevious) {
            $importData = $this->getImportData();
            if ($importData) {
                $imported = PostcodeImportModel::where('md5_hash', $importData['md5'])
                    ->first();
            }
        }

        if ($imported && !$force) {
            $this->info('This data has already been imported');

            return;
        }

        // Check the download URL is in a location we would expect
        if (!$usePrevious && str_starts_with($importData['url'], config('services.os_datahub.url'))) {
            Storage::put(self::ZIP_FILENAME, file_get_contents($importData['url']));

            // If this import already exists we will just update the updated_at time.
            // Normally I would put decent logging around any such console commands,
            // but this felt like overkill
            PostcodeImportModel::updateOrCreate([
                'md5_hash' => $importData['md5'],
                'size' => $importData['size'],
            ], [
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->extractPostcodes(self::ZIP_FILENAME);
    }

    /**
     * Get's the latest postcode data from the Ordnance Survey API or returns false if something seems amiss
     *
     * @return array|bool
     */
    public function getImportData() : array|bool
    {
        $response = Http::get(config('services.os_datahub.url') . '/products/CodePointOpen/downloads', [
            'key' => config('services.os_datahub.api_key'),
            'format' => 'CSV',
        ]);

        if ($response->failed()) {
            $this->error('Failed to access postcode data');

            return false;
        }

        $importData = $response->json();
        if (count($importData) == 0) {
            $this->error('Unexpected data returned from postcode import');

            return false;
        }

        return $importData[0];
    }

    /**
     * Extract all CSV's from the zip file and create parsing jobs for them
     *
     * @param $zipfilename
     * @return bool
     */
    protected function extractPostcodes($zipfilename) : bool
    {
        $storageDirectory = 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;
        $postcodeCSVFiles = $storageDirectory . 'postcode-csv-files' . DIRECTORY_SEPARATOR;
        $expectedDirectory = 'Data' . DIRECTORY_SEPARATOR . 'CSV' . DIRECTORY_SEPARATOR;
        $allowedFileType = '.csv';

        $zip = new \ZipArchive();
        if ($zip->open($storageDirectory . $zipfilename) !== true) {
            $this->error('Failed to extract postcodes');

            return false;
        }

        $extractedCount = 0;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            // We only care about CSV's in a specific folder, this filters out all unwanted content
            if (!str_starts_with($entry, $expectedDirectory) || !str_ends_with($entry, $allowedFileType)) {
                continue;
            }

            // Strip out the directory structure from the file
            $filename = substr($entry, 9);
            copy('zip://' . $storageDirectory . $zipfilename . '#' . $entry, $postcodeCSVFiles . $filename);

            // Checking a file extension isn't a great way to confirm file type, so lets double check before
            // processing this file any further.
            if (finfo_file($finfo, $postcodeCSVFiles . $filename) != "text/csv") {
                // Cleanup if this isn't a real CSV file
                unlink($postcodeCSVFiles . $filename);
                continue;
            }

            $extractedCount++;
            // Now we are happy that this is a CSV file we will process it in a job, this reduces
            // the load of this console command allowing us to handle the parsing elsewhere
            ProcessPostcodeCSV::dispatch($filename);
        }

        if ($extractedCount === 0) {
            $this->error('0 CSV files extracted');
        }
        else {
            $this->info($extractedCount . ' CSV ' . Str::plural('file', $extractedCount) . ' extracted');
        }

        return true;
    }
}
