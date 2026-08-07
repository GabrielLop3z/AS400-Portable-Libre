<?php

namespace App;

class AS400Connector {
    private $conn;
    private $host;
    private $username;
    private $password;

    public function __construct($host, $username, $password) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
    }

    public function connect() {
        $this->conn = @ftp_connect($this->host);
        if (!$this->conn) {
            throw new \Exception("Could not connect to AS/400 at {$this->host}. Verify IP and network.");
        }

        if (!@ftp_login($this->conn, $this->username, $this->password)) {
            $error = "Authentication failed for user {$this->username}.";
            ftp_close($this->conn);
            throw new \Exception($error);
        }

        // Use NAMEFMT 1 for easier pathing
        @ftp_raw($this->conn, "SITE NAMEFMT 1");
        return true;
    }

    /**
     * Executes an AS/400 command via FTP RCMD and returns output for debugging
     */
    public function executeCommand($cmd) {
        // Try QUOTE RCMD (more standard across different FTP server versions)
        $result = @ftp_raw($this->conn, "QUOTE RCMD $cmd");
        return $result;
    }

    /**
     * Fetches a spooled file by converting it to a temporary physical file first
     */
    public function fetchSpool($file, $job, $number) {
        $tempFile = "SP" . substr(time(), -8) . "T";
        
        // Force NAMEFMT 1
        @ftp_raw($this->conn, "SITE NAMEFMT 1");

        // Step 1: Create physical file in QTEMP
        $res1 = $this->executeCommand("CRTPF FILE(QTEMP/$tempFile) RCDLEN(256) SIZE(*NOMAX)");
        
        // Step 2: Copy Spooled File to Physical File
        $splNbr = $number ?: "*LAST";
        $cmd = "CPYSPLF FILE($file) TOFILE(QTEMP/$tempFile) JOB($job) SPLNBR($splNbr) MBROPT(*REPLACE)";
        $res2 = $this->executeCommand($cmd);
        
        $log = "PF_CREATE: " . implode(" ", $res1) . " | CPYSPLF: " . implode(" ", $res2);

        // Step 3: Download the member
        $localFile = tempnam(sys_get_temp_dir(), 'as400_');
        $remotePath = "/QSYS.LIB/QTEMP.LIB/$tempFile.FILE/$tempFile.MBR";
        
        // Set to ASCII for report text (call from global namespace)
        \ftp_ascii($this->conn);
        
        if (@ftp_get($this->conn, $localFile, $remotePath, FTP_ASCII)) {
            $content = file_get_contents($localFile);
            @unlink($localFile);
            $this->executeCommand("DLTF FILE(QTEMP/$tempFile)");
            return $content;
        } else {
            // Diagnostic: Try to see if file exists or what happened
            $errorMsg = "Direct Spool Retrieval failed. \nPath: $remotePath \nServer Log: " . $log;
            throw new \Exception($errorMsg);
        }
    }

    public function close() {
        if ($this->conn) {
            @ftp_close($this->conn);
        }
    }

    /**
     * Lists spools for the current user (Compleo Explorer Style)
     */
    public function listSpools($user = "*CURRENT") {
        $tempFile = "LX" . substr(time(), -8) . "T";
        @ftp_raw($this->conn, "SITE NAMEFMT 1");

        // Step 1: Create a temp pf
        $this->executeCommand("CRTPF FILE(QTEMP/$tempFile) RCDLEN(132) SIZE(*NOMAX)");
        
        // Step 2: Use WRKSPLF. We'll try to find the output printer file.
        $this->executeCommand("WRKSPLF SELECT(*CURRENT) OUTPUT(*PRINT)");
        
        // Try common spool output names
        $fileNames = ['QPRTSPLF', 'QPSYSPRT', 'QPSPLPRT'];
        $copySuccess = false;
        $logs = [];
        
        foreach ($fileNames as $fName) {
            $res = $this->executeCommand("CPYSPLF FILE($fName) TOFILE(QTEMP/$tempFile) JOB(*) SPLNBR(*LAST)");
            $logs[] = $fName . ": " . implode(" ", $res);
            if (!str_contains(implode(" ", $res), "CPF3303") && !str_contains(implode(" ", $res), "550")) {
                $copySuccess = true;
                break;
            }
        }

        $localFile = tempnam(sys_get_temp_dir(), 'aslist_');
        $remotePath = "/QSYS.LIB/QTEMP.LIB/$tempFile.FILE/$tempFile.MBR";
        
        $spools = [];
        if ($copySuccess && @ftp_get($this->conn, $localFile, $remotePath, FTP_ASCII)) {
            $lines = file($localFile);
            foreach ($lines as $line) {
                $line = trim($line, "\r\n");
                if (strlen($line) < 40) continue;
                
                // Typical line parsing
                $f1 = trim(substr($line, 0, 12)); 
                $f2 = trim(substr($line, 13, 10)); 
                $status = trim(substr($line, 36, 5)); 
                $pages = trim(substr($line, 42, 6)); 
                $date = trim(substr($line, 49, 8));
                
                if (!empty($f1) && strlen($f1) <= 10 && $f1 !== "Archivo" && $f1 !== "File" && !str_contains($line, "---")) {
                    if (in_array(strtoupper($status), ['RDY', 'HLD', 'SAV', 'CLO', 'OPN', 'PND', 'WTR'])) {
                        $spools[] = [
                            'name' => $f1,
                            'user' => $f2,
                            'status' => $status,
                            'pages' => is_numeric($pages) ? $pages : '?',
                            'date' => $date,
                            'job' => '*'
                        ];
                    }
                }
            }
            @unlink($localFile);
            $this->executeCommand("DLTF FILE(QTEMP/$tempFile)");
        } else if (!$copySuccess) {
            throw new \Exception("Could not capture WRKSPLF output. Logs: " . implode(" | ", $logs));
        }
        
        return $spools;
    }
}
