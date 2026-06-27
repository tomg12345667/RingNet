<?php
/**
 * Call Blast - FreePBX Module
 *
 * Allows administrators to initiate an outbound call from the FreePBX GUI
 * that plays a custom recorded message to the recipient.
 *
 * @package   callblast
 * @license   GPLv2
 */

namespace FreePBX\modules;

class Callblast extends \FreePBX_Helpers implements \BMO_Module {

    public function __construct($freepbx = null) {
        parent::__construct($freepbx);
        $this->FreePBX = $freepbx;
    }

    // Required BMO stubs
    public static function install()   {}
    public static function uninstall() {}
    public function showPage()         {}

    // -------------------------------------------------------------------------
    // Recordings
    // -------------------------------------------------------------------------

    /**
     * Return all .wav / .gsm / .ulaw / .alaw files found in the custom sounds
     * directory so the page can populate the recording dropdown.
     *
     * @return array  [ ['file' => 'custom/filename', 'label' => 'filename.wav'], ... ]
     */
    public function getRecordings() {
        $recordings = [];
        $path       = '/var/lib/asterisk/sounds/custom/';

        if (!is_dir($path)) {
            return $recordings;
        }

        foreach (glob($path . '*.{wav,gsm,ulaw,alaw}', GLOB_BRACE) as $file) {
            $basename    = basename($file);
            $noext       = pathinfo($basename, PATHINFO_FILENAME);
            $recordings[] = [
                'file'  => 'custom/' . $noext,
                'label' => $basename,
            ];
        }

        return $recordings;
    }

    // -------------------------------------------------------------------------
    // Call file
    // -------------------------------------------------------------------------

    /**
     * Build and atomically drop a .call file into the Asterisk outgoing spool.
     *
     * @param  string $number     E.164 or dialable number, e.g. 15551234567
     * @param  string $recording  Sounds-relative path, e.g. custom/my_message
     * @param  string $trunk      PJSIP endpoint / trunk name
     * @param  string $callerid   Caller ID string, e.g. "My System <15559876543>"
     * @param  string $protocol   'PJSIP' (default) or 'SIP'
     * @return array  ['success' => bool, 'msg' => string]
     */
    public function initiateCall($number, $recording, $trunk, $callerid, $protocol = 'PJSIP') {
        // ---- Sanitise -------------------------------------------------------
        $number    = preg_replace('/[^0-9+]/',             '', $number);
        $recording = preg_replace('/[^a-zA-Z0-9\/_\-]/',  '', $recording);
        $trunk     = preg_replace('/[^a-zA-Z0-9\/_\-]/',  '', $trunk);
        $callerid  = preg_replace('/[^a-zA-Z0-9 <>@._+\-]/', '', $callerid);
        $protocol  = ($protocol === 'SIP') ? 'SIP' : 'PJSIP';

        if (empty($number)) {
            return ['success' => false, 'msg' => 'Destination number is required.'];
        }
        if (empty($recording)) {
            return ['success' => false, 'msg' => 'A recording must be selected.'];
        }
        if (empty($trunk)) {
            return ['success' => false, 'msg' => 'A trunk must be selected.'];
        }

        // ---- Build channel string -------------------------------------------
        // PJSIP:  PJSIP/15551234567@mytrunk
        // SIP:    SIP/mytrunk/15551234567
        if ($protocol === 'PJSIP') {
            $channel = "PJSIP/{$number}@{$trunk}";
        } else {
            $channel = "SIP/{$trunk}/{$number}";
        }

        // ---- Build .call content --------------------------------------------
        $callContent  = "Channel: {$channel}\n";
        $callContent .= "MaxRetries: 2\n";
        $callContent .= "RetryTime: 60\n";
        $callContent .= "WaitTime: 30\n";
        $callContent .= "Context: callblast-playback\n";
        $callContent .= "Extension: s\n";
        $callContent .= "Priority: 1\n";
        $callContent .= "Setvar: CALLBLAST_MSG={$recording}\n";
        $callContent .= "Callerid: {$callerid}\n";

        // ---- Write to temp, then atomically move into spool -----------------
        $tmpFile  = tempnam('/tmp', 'callblast_');
        $callFile = '/var/spool/asterisk/outgoing/' . basename($tmpFile) . '.call';

        if (file_put_contents($tmpFile, $callContent) === false) {
            return ['success' => false, 'msg' => 'Failed to write temporary call file.'];
        }

        chmod($tmpFile, 0644);

        // chown only works when running as root; silently skip if not
        @chown($tmpFile, 'asterisk');

        if (!rename($tmpFile, $callFile)) {
            @unlink($tmpFile);
            return ['success' => false, 'msg' => 'Failed to move call file into spool. Check permissions on /var/spool/asterisk/outgoing/.'];
        }

        return ['success' => true, 'msg' => "Call queued to {$number} via {$trunk}."];
    }

    // -------------------------------------------------------------------------
    // Dialplan
    // -------------------------------------------------------------------------

    /**
     * Append the [callblast-playback] context to extensions_custom.conf if it
     * is not already present, then reload the dialplan.
     *
     * Called automatically by install.php on module installation.
     */
    public function writeDialplan() {
        $context  = "\n; --- callblast module ---\n";
        $context .= "[callblast-playback]\n";
        $context .= "exten => s,1,Answer()\n";
        $context .= " same => n,Wait(1)\n";
        $context .= " same => n,Playback(\${CALLBLAST_MSG})\n";
        $context .= " same => n,Hangup()\n";
        $context .= "; --- end callblast ---\n";

        $file = '/etc/asterisk/extensions_custom.conf';

        // Create the file if it somehow does not exist
        if (!file_exists($file)) {
            file_put_contents($file, '');
        }

        $existing = file_get_contents($file);

        if (strpos($existing, '[callblast-playback]') === false) {
            file_put_contents($file, $existing . $context);
            shell_exec('asterisk -rx "dialplan reload" 2>&1');
        }
    }

    /**
     * Remove the [callblast-playback] context from extensions_custom.conf.
     *
     * Called by uninstall.php on module removal.
     */
    public function removeDialplan() {
        $file = '/etc/asterisk/extensions_custom.conf';

        if (!file_exists($file)) {
            return;
        }

        $existing = file_get_contents($file);

        // Strip everything between the callblast markers (inclusive)
        $cleaned = preg_replace(
            '/\n; --- callblast module ---.*?; --- end callblast ---\n/s',
            '',
            $existing
        );

        file_put_contents($file, $cleaned);
        shell_exec('asterisk -rx "dialplan reload" 2>&1');
    }
}
