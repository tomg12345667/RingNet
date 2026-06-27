cat > /var/www/html/admin/modules/RingNet/freepbx-modules/callblast/Callblast.class.php << 'EOF'
<?php
namespace FreePBX\modules;

use FreePBX_Helpers;
use BMO;

class Callblast extends FreePBX_Helpers implements BMO {

    public function __construct($freepbx = null) {
        parent::__construct($freepbx);
        $this->FreePBX = $freepbx;
    }

    public static function install()   {}
    public static function uninstall() {}
    public function showPage()         {}

    public function getRecordings() {
        $recordings = [];
        $path = '/var/lib/asterisk/sounds/custom/';
        if (!is_dir($path)) {
            return $recordings;
        }
        foreach (glob($path . '*.{wav,gsm,ulaw,alaw}', GLOB_BRACE) as $file) {
            $basename = basename($file);
            $noext = pathinfo($basename, PATHINFO_FILENAME);
            $recordings[] = [
                'file'  => 'custom/' . $noext,
                'label' => $basename,
            ];
        }
        return $recordings;
    }

    public function initiateCall($number, $recording, $trunk, $callerid, $protocol = 'PJSIP') {
        $number    = preg_replace('/[^0-9+]/', '', $number);
        $recording = preg_replace('/[^a-zA-Z0-9\/_\-]/', '', $recording);
        $trunk     = preg_replace('/[^a-zA-Z0-9\/_\-]/', '', $trunk);
        $callerid  = preg_replace('/[^a-zA-Z0-9 <>@._+\-]/', '', $callerid);
        $protocol  = ($protocol === 'SIP') ? 'SIP' : 'PJSIP';

        if (empty($number))    return ['success' => false, 'msg' => 'Destination number is required.'];
        if (empty($recording)) return ['success' => false, 'msg' => 'A recording must be selected.'];
        if (empty($trunk))     return ['success' => false, 'msg' => 'A trunk must be selected.'];

        if ($protocol === 'PJSIP') {
            $channel = "PJSIP/{$number}@{$trunk}";
        } else {
            $channel = "SIP/{$trunk}/{$number}";
        }

        $callContent  = "Channel: {$channel}\n";
        $callContent .= "MaxRetries: 2\n";
        $callContent .= "RetryTime: 60\n";
        $callContent .= "WaitTime: 30\n";
        $callContent .= "Context: callblast-playback\n";
        $callContent .= "Extension: s\n";
        $callContent .= "Priority: 1\n";
        $callContent .= "Setvar: CALLBLAST_MSG={$recording}\n";
        $callContent .= "Callerid: {$callerid}\n";

        $tmpFile  = tempnam('/tmp', 'callblast_');
        $callFile = '/var/spool/asterisk/outgoing/' . basename($tmpFile) . '.call';

        if (file_put_contents($tmpFile, $callContent) === false) {
            return ['success' => false, 'msg' => 'Failed to write temporary call file.'];
        }

        chmod($tmpFile, 0644);
        @chown($tmpFile, 'asterisk');

        if (!rename($tmpFile, $callFile)) {
            @unlink($tmpFile);
            return ['success' => false, 'msg' => 'Failed to move call file into spool.'];
        }

        return ['success' => true, 'msg' => "Call queued to {$number} via {$trunk}."];
    }

    public function writeDialplan() {
        $context  = "\n; --- callblast module ---\n";
        $context .= "[callblast-playback]\n";
        $context .= "exten => s,1,Answer()\n";
        $context .= " same => n,Wait(1)\n";
        $context .= " same => n,Playback(\${CALLBLAST_MSG})\n";
        $context .= " same => n,Hangup()\n";
        $context .= "; --- end callblast ---\n";

        $file = '/etc/asterisk/extensions_custom.conf';
        if (!file_exists($file)) {
            file_put_contents($file, '');
        }

        $existing = file_get_contents($file);
        if (strpos($existing, '[callblast-playback]') === false) {
            file_put_contents($file, $existing . $context);
            shell_exec('asterisk -rx "dialplan reload" 2>&1');
        }
    }

    public function removeDialplan() {
        $file = '/etc/asterisk/extensions_custom.conf';
        if (!file_exists($file)) return;

        $existing = file_get_contents($file);
        $cleaned  = preg_replace(
            '/\n; --- callblast module ---.*?; --- end callblast ---\n/s',
            '',
            $existing
        );
        file_put_contents($file, $cleaned);
        shell_exec('asterisk -rx "dialplan reload" 2>&1');
    }
}
EOF
