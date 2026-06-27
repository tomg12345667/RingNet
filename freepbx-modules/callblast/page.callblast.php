<?php
$cb = FreePBX::create()->Callblast;

$alertHtml = '';

if (!empty($_POST['action']) && $_POST['action'] === 'initcall') {
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
        $alertClass, $alertIcon, htmlspecialchars($result['msg'])
    );
}

$recordings = $cb->getRecordings();

try {
    $trunks = FreePBX::create()->Core->getAllTrunks();
} catch (Exception $e) {
    $trunks = [];
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-sm-12">
            <h1><?php echo _("Call Blast"); ?></h1>
            <p class="text-muted"><?php echo _("Initiate an outbound call and play a custom message to the recipient."); ?></p>
            <hr>
        </div>
    </div>

    <?php if ($alertHtml): ?>
    <div class="row">
        <div class="col-sm-8"><?php echo $alertHtml; ?></div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-sm-8">
            <form method="POST" action="config.php?display=callblast">
                <input type="hidden" name="action" value="initcall">

                <div class="form-group">
                    <label><?php echo _("Destination Number"); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="number" class="form-control" placeholder="15551234567" required
                           value="<?php echo htmlspecialchars(isset($_POST['number']) ? $_POST['number'] : ''); ?>">
                </div>

                <div class="form-group">
                    <label><?php echo _("Outbound Trunk"); ?> <span class="text-danger">*</span></label>
                    <?php if (empty($trunks)): ?>
                        <input type="text" name="trunk" class="form-control" placeholder="mytrunk" required>
                    <?php else: ?>
                        <select name="trunk" class="form-control" required>
                            <option value="">-- <?php echo _("Select Trunk"); ?> --</option>
                            <?php foreach ($trunks as $trunk): ?>
                            <option value="<?php echo htmlspecialchars($trunk['name']); ?>">
                                <?php echo htmlspecialchars($trunk['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label><?php echo _("Trunk Protocol"); ?></label>
                    <select name="protocol" class="form-control">
                        <option value="PJSIP">PJSIP (recommended)</option>
                        <option value="SIP">SIP (legacy)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><?php echo _("Message Recording"); ?> <span class="text-danger">*</span></label>
                    <?php if (empty($recordings)): ?>
                        <div class="alert alert-warning"><?php echo _("No recordings found. Add one via Admin → System Recordings."); ?></div>
                    <?php else: ?>
                        <select name="recording" class="form-control" required>
                            <option value="">-- <?php echo _("Select Recording"); ?> --</option>
                            <?php foreach ($recordings as $rec): ?>
                            <option value="<?php echo htmlspecialchars($rec['file']); ?>">
                                <?php echo htmlspecialchars($rec['label']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <small class="help-block"><?php echo _("Add recordings via"); ?> <a href="config.php?display=recordings"><?php echo _("Admin → System Recordings"); ?></a>.</small>
                </div>

                <div class="form-group">
                    <label><?php echo _("Caller ID"); ?></label>
                    <input type="text" name="callerid" class="form-control"
                           placeholder="My System <15559876543>"
                           value="<?php echo htmlspecialchars(isset($_POST['callerid']) ? $_POST['callerid'] : 'My System <15559876543>'); ?>">
                </div>

                <hr>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fa fa-phone"></i> <?php echo _("Send Call Now"); ?>
                </button>
            </form>
        </div>

        <div class="col-sm-4">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="fa fa-info-circle"></i> <?php echo _("How it works"); ?></h3>
                </div>
                <div class="panel-body">
                    <ol>
                        <li><?php echo _("FreePBX writes a .call file to the Asterisk spool."); ?></li>
                        <li><?php echo _("Asterisk dials the destination number via the selected trunk."); ?></li>
                        <li><?php echo _("When answered, the selected recording is played."); ?></li>
                        <li><?php echo _("The call hangs up automatically after the message."); ?></li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>
