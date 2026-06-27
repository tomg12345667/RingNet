<?php
/**
 * Call Blast - Admin Page
 *
 * Rendered by FreePBX when the user navigates to Admin → Call Blast.
 * Handles both GET (display form) and POST (submit call).
 */

// Bootstrap FreePBX module object
$cb = \FreePBX::create()->Callblast;

// ---- Handle form submission -------------------------------------------------
$alertHtml = '';

if (!empty($_POST['action']) && $_POST['action'] === 'initcall') {
    // Verify FreePBX CSRF token
    if (function_exists('checkCsrfToken')) {
        checkCsrfToken();
    }

    $result = $cb->initiateCall(
        isset($_POST['number'])    ? $_POST['number']    : '',
        isset($_POST['recording']) ? $_POST['recording'] : '',
        isset($_POST['trunk'])     ? $_POST['trunk']     : '',
        isset($_POST['callerid'])  ? $_POST['callerid']  : 'CallBlast <0000000000>',
        isset($_POST['protocol'])  ? $_POST['protocol']  : 'PJSIP'
    );

    $alertClass = $result['success'] ? 'alert-success' : 'alert-danger';
    $alertIcon  = $result['success'] ? 'fa-check-circle' : 'fa-exclamation-circle';
    $alertHtml  = sprintf(
        '<div class="alert %s"><i class="fa %s"></i> %s</div>',
        $alertClass,
        $alertIcon,
        htmlspecialchars($result['msg'])
    );
}

// ---- Load data for dropdowns ------------------------------------------------
$recordings = $cb->getRecordings();

// Pull trunks from FreePBX Core
try {
    $trunks = \FreePBX::create()->Core->getAllTrunks();
} catch (\Exception $e) {
    $trunks = [];
}
?>

<div class="container-fluid">

    <!-- Page header -->
    <div class="row">
        <div class="col-sm-12">
            <h1><?php echo _("Call Blast"); ?></h1>
            <p class="text-muted">
                <?php echo _("Initiate an outbound call and play a custom message to the recipient."); ?>
            </p>
            <hr>
        </div>
    </div>

    <!-- Alert (shown after submit) -->
    <?php if ($alertHtml): ?>
    <div class="row">
        <div class="col-sm-8">
            <?php echo $alertHtml; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Call form -->
    <div class="row">
        <div class="col-sm-8">
            <form method="POST" action="config.php?display=callblast" id="callblast-form">
                <input type="hidden" name="action" value="initcall">

                <?php
                // Output FreePBX CSRF token if available
                if (function_exists('get_csrf_field')) {
                    echo get_csrf_field();
                }
                ?>

                <!-- Destination number -->
                <div class="form-group">
                    <label for="cb-number">
                        <?php echo _("Destination Number"); ?>
                        <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           id="cb-number"
                           name="number"
                           class="form-control"
                           placeholder="15551234567"
                           pattern="[0-9+]{7,20}"
                           title="Digits only, 7–20 characters"
                           required
                           value="<?php echo htmlspecialchars(isset($_POST['number']) ? $_POST['number'] : ''); ?>">
                    <small class="help-block"><?php echo _("Enter the full dialable number including country code."); ?></small>
                </div>

                <!-- Trunk -->
                <div class="form-group">
                    <label for="cb-trunk">
                        <?php echo _("Outbound Trunk"); ?>
                        <span class="text-danger">*</span>
                    </label>
                    <?php if (empty($trunks)): ?>
                        <div class="alert alert-warning">
                            <?php echo _("No trunks found. Please configure a trunk under Connectivity first."); ?>
                        </div>
                        <input type="text"
                               id="cb-trunk"
                               name="trunk"
                               class="form-control"
                               placeholder="mytrunk"
                               required
                               value="<?php echo htmlspecialchars(isset($_POST['trunk']) ? $_POST['trunk'] : ''); ?>">
                    <?php else: ?>
                        <select id="cb-trunk" name="trunk" class="form-control" required>
                            <option value="">-- <?php echo _("Select Trunk"); ?> --</option>
                            <?php foreach ($trunks as $trunk):
                                $selected = (isset($_POST['trunk']) && $_POST['trunk'] === $trunk['name']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo htmlspecialchars($trunk['name']); ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($trunk['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <!-- Protocol -->
                <div class="form-group">
                    <label for="cb-protocol"><?php echo _("Trunk Protocol"); ?></label>
                    <select id="cb-protocol" name="protocol" class="form-control">
                        <option value="PJSIP" <?php echo (!isset($_POST['protocol']) || $_POST['protocol'] === 'PJSIP') ? 'selected' : ''; ?>>
                            PJSIP (recommended)
                        </option>
                        <option value="SIP" <?php echo (isset($_POST['protocol']) && $_POST['protocol'] === 'SIP') ? 'selected' : ''; ?>>
                            SIP (legacy chan_sip)
                        </option>
                    </select>
                    <small class="help-block">
                        <?php echo _("Most modern FreePBX installs use PJSIP. Use SIP only for older chan_sip trunks."); ?>
                    </small>
                </div>

                <!-- Recording -->
                <div class="form-group">
                    <label for="cb-recording">
                        <?php echo _("Message Recording"); ?>
                        <span class="text-danger">*</span>
                    </label>
                    <?php if (empty($recordings)): ?>
                        <div class="alert alert-warning">
                            <?php echo _("No custom recordings found in /var/lib/asterisk/sounds/custom/. Add one via Admin → System Recordings first."); ?>
                        </div>
                    <?php else: ?>
                        <select id="cb-recording" name="recording" class="form-control" required>
                            <option value="">-- <?php echo _("Select Recording"); ?> --</option>
                            <?php foreach ($recordings as $rec):
                                $selected = (isset($_POST['recording']) && $_POST['recording'] === $rec['file']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo htmlspecialchars($rec['file']); ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($rec['label']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <small class="help-block">
                        <?php echo _("Create recordings via"); ?>
                        <a href="config.php?display=recordings" target="_blank">
                            <?php echo _("Admin → System Recordings"); ?>
                        </a>.
                    </small>
                </div>

                <!-- Caller ID -->
                <div class="form-group">
                    <label for="cb-callerid"><?php echo _("Caller ID"); ?></label>
                    <input type="text"
                           id="cb-callerid"
                           name="callerid"
                           class="form-control"
                           placeholder="My System <15559876543>"
                           value="<?php echo htmlspecialchars(isset($_POST['callerid']) ? $_POST['callerid'] : 'My System <15559876543>'); ?>">
                    <small class="help-block"><?php echo _("What the recipient sees as the incoming caller ID."); ?></small>
                </div>

                <hr>

                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fa fa-phone"></i>&nbsp;<?php echo _("Send Call Now"); ?>
                </button>

            </form>
        </div>

        <!-- Help panel -->
        <div class="col-sm-4">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="fa fa-info-circle"></i> <?php echo _("How it works"); ?></h3>
                </div>
                <div class="panel-body">
                    <ol>
                        <li><?php echo _("FreePBX writes a <code>.call</code> file to the Asterisk spool."); ?></li>
                        <li><?php echo _("Asterisk immediately dials the destination number via the selected trunk."); ?></li>
                        <li><?php echo _("When the call is answered, the selected recording is played."); ?></li>
                        <li><?php echo _("The call is automatically hung up after the message finishes."); ?></li>
                    </ol>
                    <hr>
                    <p><strong><?php echo _("Tip:"); ?></strong>
                        <?php echo _("Record your message first via Admin → System Recordings. Files are stored in <code>/var/lib/asterisk/sounds/custom/</code>."); ?>
                    </p>
                </div>
            </div>
        </div>
    </div><!-- /.row -->

</div><!-- /.container-fluid -->
