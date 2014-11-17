<?php
error_reporting(E_ALL);
define('APP_ROOT', 'http://' . $_SERVER['HTTP_HOST'] . '/messdetector/');
define('PROJECT_ROOT', $_SERVER['DOCUMENT_ROOT'] . '/messdetector');
define('REPORT_PATH', PROJECT_ROOT . '/reports');
define('ERROR_LOG_FILE', PROJECT_ROOT . '/logs/error.log');
define('REPORT_LOG_FILE', PROJECT_ROOT . '/logs/report.log');
define('DOWNLOAD_REPORT_PATH', APP_ROOT . '/reports');
define('UPLOAD_PATH', PROJECT_ROOT . '/uploads');

class MessDetectorCheck {
    private $phpmd_path = '/Applications/MAMP/bin/php/php5.6.2/bin/phpmd';
    private $upload_path = UPLOAD_PATH;
    private $report_path = REPORT_PATH;

    public function __construct() {
        
    }

    private function getBrowserInfo() {
        return $_SERVER['HTTP_USER_AGENT'];
    }

    private function getIPaddress() {
        if (getenv('HTTP_X_FORWARDED_FOR')) {
            $ipaddress = getenv('REMOTE_ADDR');
        } else {
            $ipaddress = getenv('REMOTE_ADDR');
        }
        return $ipaddress;
    }

    public function log($type, $message) {
        switch ($type) {
            case 'err':
                $log_file = ERROR_LOG_FILE;
                $report_type = 'error';
                break;
            case 'report':
                $log_file = REPORT_LOG_FILE;
                $report_type = 'report';
                break;
            default:
                $log_file = ERROR_LOG_FILE;
                $report_type = 'error';
                break;
        }
        $handle = fopen($log_file, 'a') or die('Cannot open file:  ' . $log_file);
        $data = "[" . date('D M H:i:s Y') . "]\t[IP: " . $this->getIPaddress() . "]\t[Browser: " . $this->getBrowserInfo() . "]\t[" . $report_type . "]\t" . $message . "\n";

        fwrite($handle, $data);
        fclose($handle);
    }

    public function uploadFile($file) {
        $allowedExts = array("php");
        $temp = explode(".", $file["file"]["name"]);
        $extension = end($temp);

        //if (($file["file"]["type"] == "text/php") && in_array($extension, $allowedExts)) {
        if (in_array($extension, $allowedExts)) {
            if ($file["file"]["error"] > 0) {
                $this->log('err', 'File error: ' . $file["file"]["error"]);
                die(json_encode(array('error' => 1, 'message' => 'Return Code: ' . $file["file"]["error"])));
            } else {
                $file_name_chunks = explode('.', $file["file"]["name"]);
                if (file_exists($this->upload_path . "/" . $file["file"]["name"])) {
                    $message = 'File exists: ' . $file["file"]["error"];
                    $this->log('err', $message);
                    die(json_encode(array('error' => 1, 'message' => $message)));
                } else {
                    move_uploaded_file($file["file"]["tmp_name"], $this->upload_path . "/" . $file["file"]["name"]);

                    $report_file = $this->createFile($file_name_chunks[0]);
                    $check_file = $this->upload_path . "/" . $file["file"]["name"];
                    $mess_output = $this->execMessDetector($check_file, $report_file);

                    return $mess_output;
                }
            }
        } else {
            $message = 'File type invalid';
            $this->log('err', $message);
            die(json_encode(array('error' => 1, 'message' => $message)));
        }
    }

    private function createFile($file_name) {
        return $file_name . '_' . time() . strrchr(strtolower($file_name), '.') . '.txt';
    }

    private function execMessDetector($file, $report) {
        $full_report_file_name = 'full_' . $report;
        $full_report_file = $this->report_path . '/' . $full_report_file_name;
        $handle_full = fopen($full_report_file, 'w') or die('Cannot open file:  ' . $full_report_file); //implicitly creates file
        
        $exec = shell_exec($this->phpmd_path . ' ' . $file . ' text codesize,unusedcode,naming --reportfile ' . $full_report_file);

        $message = "Report generated::\tInput file name: " . $file . "\tFull report file: " . $full_report_file;
        $this->log('report', $message);

        fclose($handle_full);
        unlink($file);

        return array('output' => $exec, 'full_report_file' => $full_report_file_name);
    }

    public function readFile($file_read) {
        $file = fopen($this->report_path . '/' . $file_read, "r");
        while (!feof($file)) {
            $members[] = fgets($file);
        }
        fclose($file);

        $report = '<ul>';
        foreach ($members as $x) {
            $report .= '<li>' . $x . '</li>'; // do something with each line from text file here
        }
        $report .= '</ul>';

        return $report;
    }

    public function reportLink($file) {
        return DOWNLOAD_REPORT_PATH . '/' . $file;
    }

}

if (isset($_FILES)) {
    $check = new MessDetectorCheck();
    $output = $check->uploadFile($_FILES);

    $full_report_link = $check->reportLink($output['full_report_file']);
    $full_report = $check->readFile($output['full_report_file']);

    die(json_encode(array('error' => 0, 'message' => $output, 'uploaded_file_name' => $_FILES["file"]["name"], 'full_report' => $full_report, 'full_report_link' => $full_report_link)));
} else {
    $message = 'No files uploaded';
    $this->log('err', $message);
    die(json_encode(array('error' => 1, 'message' => $message)));
}
?>