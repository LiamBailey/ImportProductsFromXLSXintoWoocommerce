<?php
/*
  Plugin Name: Custom CSV Parser
  Description: Parses Meva CSV
  Version: 1.0.0
  Author: Liam Bailey
 */
global $Custom_CSV_Parser;
$Custom_CSV_Parser = new Custom_CSV_Parser();

class Custom_CSV_Parser {

    function __construct() {
        add_action('admin_menu', array($this, 'setup_page'));
        add_action('admin_enqueue_scripts', array($this, 'ccsvp_enqueue_scripts'));
        add_action('admin_init',array($this,'parse_xlsx_files'));
        ini_set('display_errors',1);
    }

    function setup_page() {
        add_submenu_page('edit.php?post_type=product', 'CSV Parse', 'CSV Parser', 'manage_woocommerce', 'parse_csv', array($this, 'uploader_page'));
    }

    function parse_xlsx($inputFile) {
        $dir = "/tmp";
        // Unzip
        $zip = new ZipArchive();
        $zip->open($inputFile);
        $zip->extractTo($dir);
        // Open up shared strings & the first worksheet
        $strings = simplexml_load_file($dir . '/xl/sharedStrings.xml');
        $sheet = simplexml_load_file($dir . '/xl/worksheets/sheet1.xml');
        // Parse the rows
        $xlrows = $sheet->sheetData->row;
        foreach ($xlrows as $xlrow) {
            $arr = array();

            // In each row, grab it's value
            foreach ($xlrow->c as $cell) {
                $v = (string) $cell->v;

                // If it has a "t" (type?) of "s" (string?), use the value to look up string value
                if (isset($cell['t']) && $cell['t'] == 's') {
                    $s = array();
                    $si = $strings->si[(int) $v];

                    // Register & alias the default namespace or you'll get empty results in the xpath query
                    $si->registerXPathNamespace('n', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    // Cat together all of the 't' (text?) node values
                    foreach ($si->xpath('.//n:t') as $t) {
                        $s[] = (string) $t;
                    }
                    $v = implode($s);
                }

                $arr[] = $v;
            }

            // Assuming the first row are headers, stick them in the headers array
            if (count($headers) == 0) {
                $headers = array_map('strtolower', $arr);
                foreach ($headers as &$header) {
                    $header = str_replace(" ", "_", $header);
                }
            } else {
                // Combine the row with the headers - make sure we have the same column count
                $values = array_pad($arr, count($headers), '');
                $row = array_combine($headers, $values);
                $data[] = $row;
                /**
                 * Here, do whatever you like with the [header => value] assoc array in $row.
                 * It might be useful just to run this script without any code here, to watch
                 * memory usage simply iterating over your spreadsheet.
                 */
            }
        }
        return $data;
        @unlink($dir);
        @unlink($inputFile);
    }

    function map_categories($post_id, $codes) {
        $set_terms = array();
        foreach (explode(",", $codes) as $code) {
            if (!isset($this->cats[$code])) {
                echo "Cat $code not found";
            } else {
                $set_terms[] = $this->cats[$code];
            }
        }
        echo " <p><p>Setting " . print_r($set_terms, true) . " to $post_id";
        if (!empty($set_terms))
            wp_set_object_terms($post_id, $set_terms, 'product_cat', false);
    }

    function get_status($post_id, $active) {
        $post_status = $active == 1 ? "publish" : "draft";
        if ($post_status != get_post_status($post_id)) {
            wp_update_post(array('ID' => $post_id, 'post_status' => $post_status));
        }
    }

    function product_exists($sku) {
        $search = get_posts(array('meta_key' => '_sku', 'meta_value' => $sku, 'post_type' => 'product', 'post_status' => 'any'));
        return count($search) > 0 ? reset($search)->ID : false;
    }

    function build_categories() {
        foreach ($this->catsandsubcats as $wp_key => $value) {
            echo "<p/>Starting on term " . print_r($value, true);
            if (empty($value['parent_name']))
                continue;
            if (!term_exists($value['parent_name'])) {
                $term_id = wp_insert_term($value['parent_name'], 'product_cat', array('description' => $value['miva_category_parent_code']));
                echo " <p>Term " . $value['parent_name'] . " inserted with ID " . print_r($term_id, true);
            } else {
                $term = get_term_by('name', $value['parent_name'], 'product_cat');
                $term_id = $term->term_id;
                echo " <p/>FOUND TERM WITH ID " . print_r($term_id, true);
            }
            $this->cats[$value['miva_category_parent_code']] = $value['miva_category_parent_code'];
            $child_id = wp_insert_term($value['sub_category_name__'], 'product_cat', array('description' => $value['miva_sub_category_code'], 'parent' => $term_id));
            $this->cats[$value['miva_sub_category_code']] = $value['sub_category_name__'];
            echo "<p>Inserting child term " . $value['sub_category_name__'] . " with child id = " . print_r($child_id, true) . " AND parent = " . $term_id;
        }
        echo "<p/> Categories Built<hr/>";
    }

    function save_images($post_id, $url) {
        $tmp = download_url($url);
        $file_array = array(
            'name' => basename($url),
            'tmp_name' => $tmp
        );
        echo "<p/>Starting on image $url " . print_r($file_array, true);
        // Check for download errors
        if (is_wp_error($tmp)) {
            @unlink($file_array['tmp_name']);
            return $tmp;
        }

        $id = media_handle_sideload($file_array, $post_id);
        echo " <p/>Sideload handled: " . print_r($id, true);
        // Check for handle sideload errors.
        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            return $id;
        }

        update_post_meta($post_id, '_thumbnail_id', $id);
        echo "<p/>$image_url save to $post_id with image_id $id";
    }

    function extract_data() {
            $uploads = wp_upload_dir();
            $path = $uploads['path'];
            $this->products = $this->parse_xlsx($path . "/" . $_POST['products_csv'] . ".xlsx");
            $this->catsandsubcats = $this->parse_xlsx($path . "/" . $_POST['catsandsubcats_csv'] . ".xlsx");
    }

    function parse_xlsx_files() {
        if (isset($_POST['submit_ccsvp_csvs'])) {
            $this->extract_data();
            $this->build_categories();
            $this->map_data();
            $this->import_products();
            echo implode("<p/>",$this->output);
        }
    }

    function import_products() {
        //$uploads = wp_upload_dir();
        foreach ($this->products as $header => $value) {

                $post_data['post_type'] = "product";
                foreach ($this->post_fields as $wp_key => $miva_key) {

                    $post_data[$wp_key] = $value[$miva_key];
                }
                $post_id = $this->product_exists($value['product_code']);
                if ($post_id === false) {
                    $post_id = wp_insert_post($post_data);
                    echo "<p>Post created " . print_r($post_id, true);
                } else {
                    echo "<P>Updating post $post_id";
                }
                foreach ($this->meta_fields as $wp_key => $miva_key) {
                    if (strstr($miva_key, "::")) {
                        $parts = explode("::", $miva_key);
                        update_post_meta($post_id, $wp_key, $parts[1]);
                    } else {
                        update_post_meta($post_id, $wp_key, $value[$miva_key]);
                    }
                }
                wp_set_object_terms($post_id, 'simple', 'product_type', false);
                foreach ($this->func_fields as $wp_key => $miva_key) {
                    //if not on importing on same domain replace site_url() with the domain your images live on from the export file
                    if ($miva_key == "image_url")
                        $value[$miva_key] = trailingslashit(site_url()) . basename($value[$miva_key]);
                    $this->{$wp_key}($post_id, $value[$miva_key]);
                }
                $row++;
            }
    }

    function uploader_page() {
        ?><div class="wrap">
            <form method="post" action="">
        <?php foreach (array('products', 'catsandsubcats') as $file_uploader) {
            ?><h4>Add <?php echo ucwords($file_uploader); ?> Uploader</h4>
                    <input type="text" name="<?php echo $file_uploader; ?>_csv" id="<?php echo $file_uploader; ?>_field" />
                    <button class="upload_file" id="<?php echo $file_uploader; ?>">Upload <?php echo $file_uploader; ?> csv</button>
                    <hr/><?php }
        ?>
                <p><input type="submit" name="submit_ccsvp_csvs" value="SUBMIT" /></p>
            </form>
        </div><?php
    }

    function map_data() {
        $this->meta_fields = array(
            '_sku' => 'product_code',
            '_downloadable' => 'enter_value::no',
            '_virtual' => 'enter_value::no',
            '_visibility' => 'enter_value::visible',
            '_stock' => 'enter_value::999',
            '_stock_status' => 'enter_value::instock',
            '_backorders' => 'enter_value::no',
            '_manage_stock' => 'enter_value::yes',
            '_price' => 'price',
            '_regular_price' => 'price',
            '_wc_cog_cost' => 'cost',
        );

        $this->post_fields = array(
            'post_title' => 'product_name',
            'post_excerpt' => 'description',
            'post_content' => 'description',
        );
        $this->func_fields = array(
            'map_categories' => 'category_codes',
            'save_images' => 'image_url',
            'get_status' => 'active',
        );
    }

    function ccsvp_enqueue_scripts() {
        wp_register_script('custom-csv-parser-js', plugins_url('custom-csv-parser-js.js', __FILE__), array('jquery', 'media-upload', 'thickbox'));

        wp_enqueue_script('jquery');
        wp_enqueue_script('thickbox');
        wp_enqueue_style('thickbox');

        wp_enqueue_script('media-upload');
        wp_enqueue_script('custom-csv-parser-js');
    }

}
