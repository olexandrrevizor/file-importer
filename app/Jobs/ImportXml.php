<?php
namespace App\Jobs;

use App\Modules\Importer\XmlImporter;

use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ImportXml extends Job implements ShouldQueue
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
        \Log::info('XmlImporter started.');

        $xml_importer = new XmlImporter(array('filename' => 'C:\OpenServer\domains\importer.com\files\order_data.xml', 'elem' => 'stat'));
        $xml_importer->import();

        \Log::info('XmlImporter finished.');

        return true;
    }
}