<?php
namespace App\Jobs;

use App\Modules\Importer\CsvImporter;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ImportCsv extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return bool
     */
    public function handle()
    {
        \Log::info('CsvImporter started.');

        $csv_importer = new CsvImporter(array('filename' => 'C:\OpenServer\domains\importer.com\files\order_data.csv'));
        $csv_importer->import();

        \Log::info('CsvImporter finished.');

        return true;
    }
}