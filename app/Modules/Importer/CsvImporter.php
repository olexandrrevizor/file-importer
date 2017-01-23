<?php
namespace App\Modules\Importer;

use League\Flysystem\Exception;
use App\Modules\Importer\Handler;
use App\Orders;

class CsvImporter implements Handler
{
    /**
     * file_pointer
     *
     * @var integer The current position the file is being read from
     * @access private
     */
    private $file_pointer = 0;

    /**
     * line_counter
     *
     * @var integer The current position in file
     * @access private
     */
    private $line_counter = 1;

    /**
     * formatted_chunk
     *
     * @var string Used to make sure start tags aren't missed
     * @access private
     */
    private $formatted_chunk = [];

    /**
     * options
     *
     * @var array Contains CsvImporter options
     * @access private
     */
    private $options = array(
        'filename' => 'data.csv',
        'chunk_size' => 50000,
        'column_separator' => "\t",
        'line_separator' => "\n",
        'file_columns' => array(
            'event_date',
            'posting_date',
            'event_type',
            'amount',
            'program_id',
            'program_name',
            'campaign_id',
            'campaign_name',
            'tool_id',
            'tool_name',
            'custom_id',
            'click_timestamp',
            'ebay_item_id',
            'ebay_leaf_category_id',
            'ebay_quantity_sold',
            'ebay_total_sale_amount',
            'item_site_id',
            'meta_category_id',
            'unique_transaction_id',
            'user_frequency_id',
            'earnings',
            'traffic_type',
            'item_name'
        )
    );

    /**
     * __construct
     *
     * Init settings and validate
     *
     * @param array $options The options with which to parse the file
     * @access public
     */
    public function __construct($options)
    {
        if (!is_array($options) || empty($options)) {
            \Log::info('CsvImporter $option array must configured!');
            return false;
        }

        $this->options = array_merge($this->options, (is_array($options) ? $options : array()));

        $this->options['chunk_size'] = ($this->options['chunk_size'] > 100000 || $this->options['chunk_size'] < 25000) ? 50000 : $this->options['chunk_size'];

        $basename = basename($this->options['filename']);

        if (strpos($basename, '.csv') === false) {
            \Log::info('CsvImporter file must be *.csv!');
            return false;
        }

        if (!file_exists($this->options['filename'])) {
            \Log::info('CsvImporter file doesn`t exist!');
            return false;
        }
    }

    /**
     * file_chunk_to_array
     *
     *
     * @return csv_chunk to array file to array
     * @access private
     */
    private function file_chunk_to_array($chunk, $iteration_number)
    {
        if (strlen($chunk) < 1) {
            \Log::info('Empty chunck!');
            return false;
        }

        $lines_chunks = null;
        try {
            $lines_chunks = explode($this->options['line_separator'], $chunk);
        } catch (Exception $e) {
            \Log::info("CsvImporter exception line explode:" . $e->getMessage());
            return false;
        }

        $formatted_line = [];
        $number_columns = count($this->options['file_columns']);

        if (empty($lines_chunks)) {
            \Log::info("CsvImporter empty array chunk array");
            return false;
        }

        // Remove column name
        if ($iteration_number == 1)
            array_splice($lines_chunks, 0, 1);

        $counter = 0;

        foreach ($lines_chunks as $line) {
            $line_columns = explode($this->options['column_separator'], $line);
            if (count($line_columns) != $number_columns) {
                \Log::info('CsvImporter error in line: ' . $this->line_counter);
                continue;
            }

            foreach ($this->options['file_columns'] as $i => $key)
                $formatted_line[$key] = $line_columns[$i];

            $this->formatted_chunk[] = $formatted_line;
            $this->line_counter += 1;
            $counter += 1;
        }
    }

    /**
     * import
     *
     * Main module handler
     * @return
     * @access public
     */

    public function import()
    {
        try {
            $handle = fopen($this->options['filename'], 'r');
            $i = 1;

            while (!feof($handle)) {
                $chunk_size = $this->options['chunk_size'];

                if ($this->file_pointer != 0) {
                    fseek($handle, (($i++ * $chunk_size) - $this->file_pointer));
                    $chunk_size = $chunk_size + $this->file_pointer;
                    $this->file_pointer = 0;
                }

                $data = fread($handle, $chunk_size);
                $data_length = strlen($data) - 1;
                $last = strripos($data, $this->options['chunk_size']);

                if ($data_length != $last) {
                    $this->file_pointer = $data_length - $last;
                    $formatted_data = substr($data, 0, $last);
                } else {
                    $formatted_data = $data;
                }

                $this->file_chunk_to_array($formatted_data, $i);
                $this->import_to_database();
            }
            fclose($handle);

        } catch (Exception $e) {
            throw new Exception("CsvImporter file get content chunked:" . $e->getMessage(), E_USER_NOTICE);
        }
    }

    /**
     * import_to_database
     *
     * Write to database validated data
     * @return
     * @access private
     */

    private function import_to_database()
    {
        if (empty($this->formatted_chunk) || $this->formatted_chunk == false) {
            \Log::info('CsvImporter file chunk empty!');
            return false;
        }

        foreach ($this->formatted_chunk as $index => $item) {
            if (strcmp($item['event_type'], 'Winning Bid (Revenue)') !== 0)
                continue;

            $now = date('Y-m-d H:i:s');
            $order = Orders::where('order_id', '=', $item['unique_transaction_id'])->first();
            if ($order == null) {
                // new order
                $order = new Orders();
                $order->fill([
                    'order_id' => $item['unique_transaction_id'],
                    'shop_id' => $item['program_id'],
                    'order_price' => $item['ebay_total_sale_amount'],
                    'created_at' => $item['click_timestamp'],
                    'order_imported_at' => $now
                ]);

                try {
                    $order->save();
                } catch (\Exception $e) {
                    \Log::info("CsvImporter database added error:" . $e->getMessage());
                    continue;
                }
            } else {
                // update order
                $order->fill([
                    'shop_id' => $item['program_id'],
                    'order_price' => $item['ebay_total_sale_amount'],
                    'created_at' => $item['click_timestamp'],
                ]);
                try {
                    $order->save();
                } catch (\Exception $e) {
                    \Log::info("CsvImporter database updated error:" . $e->getMessage());
                    continue;
                }
            }
        }

        unset($this->formatted_chunk);
    }
}