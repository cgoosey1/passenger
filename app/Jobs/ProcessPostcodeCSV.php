<?php

namespace App\Jobs;

use App\Models\Postcode;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPostcodeCSV implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected $csvFile) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $path = storage_path('app/postcode-csv-files/' . $this->csvFile);
        if (!file_exists($path)) {
            throw new \Exception('Could not find postcode csv to parse: ' . $this->csvFile);
        }

        $file = file(storage_path('app/postcode-csv-files/') . $this->csvFile);
        $csv = array_map('str_getcsv', $file);

        $postcodeLookup = $this->generatePostcodeLookup($this->csvFile);

        $insertData = [];
        foreach ($csv as $row) {
            $postcode = $this->sanitisePostcode($row[0]); // Postcode Column
            if (!$this->validPostcode($postcode)) {
                continue;
            }

            $eastings = (int) $row[2]; // Eastings Column
            $northings = (int) $row[3]; // Northings Column

            if (isset($postcodeLookup[$postcode])) {
                // I don't imagine there will be many times we need to update existing postcode information
                $this->updatePostcode($postcodeLookup[$postcode], $postcode, $eastings, $northings);
                continue;
            }

            $insertData[] = [
                'postcode' => $postcode,
                'eastings' => $eastings,
                'northings' => $northings,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        // Bulk insert all validated/cleaned data in batches of 1000
        foreach (array_chunk($insertData, 1000) as $data) {
            Postcode::insert($data);
        }
    }

    /**
     * Sanitise a postcode so its all lower case and contains no spaces
     *
     * @param $postcode
     * @return string
     */
    protected function sanitisePostcode($postcode) : string
    {
        return strtolower(str_replace(' ', '', $postcode));
    }

    /**
     * Generate an array with all postcodes with a specific prefix,
     * this is used for inserting/updating later.
     *
     * @param $csvFile
     * @return array
     */
    protected function generatePostcodeLookup($csvFile) : array
    {
        // I struggled a bit to come up with a nice way to insert or update data in an
        // efficient manner whilst also not over engineering. In the end I decided to
        // assume I will always be working with files like 'ab.csv' and only caring about
        // eastings & northings. I would do things quite definitely in a more flexible system.
        $existingPostcodes = Postcode::whereRaw('SUBSTRING(postcode,1,2) = ?', [substr($csvFile, 0, 2)])
            ->get();
        $postcodeLookup = [];
        foreach ($existingPostcodes as $postcode) {
            $postcodeLookup[$postcode->postcode] = [
                'eastings' => $postcode->eastings,
                'northings' => $postcode->northings,
            ];
        }

        return $postcodeLookup;
    }

    /**
     * Check if a postcode is valid, this is assuming a postcode has been sanitised
     * to lowercase and spaces removed.
     *
     * @param $postcode
     * @return bool
     */
    protected function validPostcode($postcode) : bool
    {
        $onlyAlphanumeric = preg_match('/^[a-z0-9]+$/', $postcode);

        // Only allow a postcode along if its short and doesn't contain anything funny
        if (!$onlyAlphanumeric || strlen($postcode) > 7) {
            return false;
        }

        return true;
    }

    /**
     * If any data has changed we'll update the postcode record in the db
     *
     * @param $postcodeLookup
     * @param $postcode
     * @param $eastings
     * @param $nothings
     * @return void
     */
    protected function updatePostcode($postcodeLookup, $postcode, $eastings, $northings) : void
    {
        if ($eastings != $postcodeLookup['eastings']
            || $northings != $postcodeLookup['northings']) {
            Postcode::where('postcode', $postcode)
                ->update([
                    'eastings' => $eastings,
                    'northings' => $northings,
                ]);
        }
    }
}
