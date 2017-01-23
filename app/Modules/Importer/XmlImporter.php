<?php
namespace App\Modules\Importer;

use League\Flysystem\Exception;
use App\Modules\Importer\Handler;
use App\Orders;

class XmlImporter implements Handler
{
    /**
     * file_pointer
     *
     * @var integer The current position the file is being read from
     * @access private
     */
    private $file_pointer = 0;

    /**
     * handle
     *
     * @var resource The fopen() resource
     * @access private
     */
    private $handle = null;

    /**
     * reading
     *
     * @var boolean Whether the script is currently reading the file
     * @access privater the script is currently reading the file
     * @access private
     */
    private $reading = false;

    /**
     * options
     *
     * @var array Contains XmlImporter options
     * @access private
     */
    private $options = array(
        'filename' => 'data.xml',
        'chunk_size' => 10000,
        'element' => 'item'
    );

    /**
     * file_chunk
     *
     * @var string Used to make sure start tags aren't missed
     * @access private
     */
    private $file_chunk;

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
            \Log::info('XmlImporter $option array must configured!');
            return false;
        }

        $this->options = array_merge($this->options, (is_array($options) ? $options : array()));

        $this->options['chunk_size'] = ($this->options['chunk_size'] < 64) ? 5000 : $this->options['chunk_size'];

        $basename = basename($this->options['filename']);

        if (strpos($basename, '.xml') === false) {
            \Log::info('XmlImporter file must be *.xml!');
            return false;
        }

        if (!file_exists($this->options['filename'])) {
            \Log::info('XmlImporter file doesn`t exist!');
            return false;
        }
    }

    /**
     * read_file
     *
     *
     * @return string The XML string from file
     * @access private
     */
    private function read_file()
    {
        $buffer = false;
        $open = '<' . $this->options['element'] . '>';
        $close = '</' . $this->options['element'] . '>';

        $this->reading = true;
        $store = false;
        fseek($this->handle, $this->file_pointer);

        while ($this->reading && !feof($this->handle)) {
            // store the chunk in a temporary variable
            $tmp = fread($this->handle, $this->options['chunk_size']);

            // check for the open string
            $checkOpen = strpos($tmp, $open);
            if (!$checkOpen && !($store)) {
                $checkOpen = strpos($tmp, $open);

                if ($checkOpen) {
                    // set it to the remainder
                    $checkOpen = $checkOpen % $this->options['chunk_size'];
                }
            }

            // check for the close string
            $checkClose = strpos($tmp, $close);
            if (!$checkClose && ($store)) {
                $checkClose = strpos($tmp, $close);

                if ($checkClose) {
                    // set it to the remainder plus the length of the close string itself
                    $checkClose = ($checkClose + strlen($close)) % $this->options['chunk_size'];
                }

            } elseif ($checkClose) {
                // add the length of the close string itself
                $checkClose += strlen($close);
            }

            // if we've found the opening string and we're not already reading another element
            if ($checkOpen !== false && !($store)) {
                // if we're found the end element too
                if ($checkClose !== false) {
                    $buffer .= substr($tmp, $checkOpen, ($checkClose - $checkOpen));

                    $this->file_pointer += $checkClose;

                    $this->reading = false;

                } else {
                    // append the data we know to be part of this element
                    $buffer .= substr($tmp, $checkOpen);

                    // update the pointer
                    $this->file_pointer += $this->options['chunk_size'];
                    $store = true;
                }

                // if we've found the closing element
            } elseif ($checkClose !== false) {
                // update the buffer with the data upto and including the close tag
                $buffer .= substr($tmp, 0, $checkClose);
                $this->file_pointer += $checkClose;
                $this->reading = false;

            } elseif ($store) {
                $buffer .= $tmp;
                $this->file_pointer += $this->options['chunk_size'];
            }
        }

        return $buffer;
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
            $this->handle = fopen($this->options['filename'], 'r');
            while ($xml = $this->read_file()) {
                $this->file_chunk = simplexml_load_string($xml)->children();
                $this->import_to_database();
                $this->file_chunk = '';
            }
            fclose($this->handle);

        } catch (Exception $e) {
            throw new Exception("XmlImporter file get content:" . $e->getMessage(), E_USER_NOTICE);
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
        if (empty($this->file_chunk) || $this->file_chunk == false) {
            \Log::info('XmlImporter file chunk empty!');
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $order = Orders::where('order_id', '=', (string)$this->file_chunk->order_id)->first();
        if ($order == null) {
            // new order
            $order = new Orders();
            $order->fill([
                'order_id' => (string)$this->file_chunk->order_id,
                'shop_id' => (string)$this->file_chunk->advcampaign_id,
                'status' => (string)$this->file_chunk->status,
                'cost' => (string)$this->file_chunk->cart,
                'currency' => (string)$this->file_chunk->currency,
                'created_at' => (string)$this->file_chunk->action_date,
                'order_imported_at' => $now
            ]);
            try {
                $order->save();
            } catch (\Exception $e) {
                \Log::info("XmlImporter database added error:" . $e->getMessage());
                return false;
            }
        } else {
            // update order
            $order->fill([
                'shop_id' => (string)$this->file_chunk->advcampaign_id,
                'order_status' => (string)$this->file_chunk->status,
                'order_price' => (string)$this->file_chunk->cart,
                'order_currency' => (string)$this->file_chunk->currency,
                'created_at' => (string)$this->file_chunk->action_date,
            ]);
            try {
                $order->save();
            } catch (\Exception $e) {
                \Log::info("XmlImporter database updated error:" . $e->getMessage());
                return false;
            }
        }
    }
}