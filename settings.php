<?php
require_once __DIR__ . '/config.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $maxLoans = filter_var($_POST['max_active_loans'] ?? null, FILTER_VALIDATE_INT);
    $loanDays = filter_var($_POST['loan_period_days'] ?? null, FILTER_VALIDATE_INT);
    if ($maxLoans === false || $maxLoans < 1 || $maxLoans > 20 || $loanDays === false || $loanDays < 1 || $loanDays > 90) {
        flash('danger', 'Enter a maximum loan limit from 1 to 20 and a loan period from 1 to 90 days.');
        redirect('settings.php');
    }
    $settings = [
        'max_active_loans' => [$maxLoans, 'Maximum pending or active loans allowed for one student.'],
        'loan_period_days' => [$loanDays, 'Number of days allowed for an issued book before its due date.'],
    ];
    try {
        db()->beginTransaction();
        $stmt = db()->prepare('INSERT INTO system_settings (setting_key, setting_value, description, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_by = VALUES(updated_by)');
        foreach ($settings as $key => [$value, $description]) {
            $stmt->execute([$key, (string)$value, $description, (int)current_user()['user_id']]);
        }
        db()->commit();
        audit_log('SYSTEM_SETTINGS_UPDATED', 'Updated max active loans to ' . $maxLoans . ' and loan duration to ' . $loanDays . ' days.');
        flash('success', 'System settings saved successfully. New rules will apply to future requests and issuances.');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log($exception->getMessage());
        flash('danger', 'The settings could not be saved. Please try again.');
    }
    redirect('settings.php');
}

$maxLoans = library_setting('max_active_loans', MAX_ACTIVE_LOANS);
$loanDays = library_setting('loan_period_days', LOAN_PERIOD_DAYS);
$pageTitle = 'System settings';
$activePage = 'settings';
require __DIR__ . '/includes/header.php';
?>
<div class="catalogue-intro"><div><span class="eyebrow">SYSTEM CONFIGURATION</span><h2>Set the library rules.</h2><p>Control the default borrowing policy for new requests and book issuances.</p></div><div class="catalogue-count"><strong><i class="bi bi-sliders"></i></strong><span>Admin only</span></div></div>
<div class="row g-4">
    <div class="col-lg-7"><div class="panel-card"><div class="panel-heading"><div><span class="eyebrow">BORROWING POLICY</span><h3>Loan settings</h3></div><span class="panel-icon"><i class="bi bi-calendar2-check"></i></span></div>
        <form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="col-md-6"><label class="form-label" for="max_active_loans">Maximum active / pending loans</label><input class="form-control" type="number" name="max_active_loans" id="max_active_loans" min="1" max="20" required value="<?= $maxLoans ?>"><div class="form-hint">Allowed range: 1–20 books per student.</div></div>
            <div class="col-md-6"><label class="form-label" for="loan_period_days">Loan period (days)</label><input class="form-control" type="number" name="loan_period_days" id="loan_period_days" min="1" max="90" required value="<?= $loanDays ?>"><div class="form-hint">Allowed range: 1–90 days from issue date.</div></div>
            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary px-4" type="submit"><i class="bi bi-save me-1"></i>Save settings</button></div>
        </form>
    </div></div>
    <div class="col-lg-5"><div class="panel-card h-100"><span class="eyebrow">CURRENT POLICY</span><h3 class="mt-1 mb-3" style="font-size:16px">At a glance</h3><div class="policy-metric"><span class="policy-icon"><i class="bi bi-bookmark-check"></i></span><div><strong><?= $maxLoans ?> books</strong><small>Maximum loans per student</small></div></div><div class="policy-metric"><span class="policy-icon green"><i class="bi bi-calendar-week"></i></span><div><strong><?= $loanDays ?> days</strong><small>Standard borrowing period</small></div></div><div class="permission-note mt-3"><i class="bi bi-info-circle"></i><div><strong>When do changes apply?</strong><p>New settings apply to requests approved or books issued after saving. Existing due dates are not automatically changed.</p></div></div></div></div>
</div>
<div class="panel-card mt-4"><div class="d-flex align-items-start gap-3"><span class="panel-icon"><i class="bi bi-shield-check"></i></span><div><h3 style="font-size:14px">Security and integrity note</h3><p class="muted-copy mb-0" style="font-size:11px;line-height:1.7">Only administrators can access this page. Changes are validated on the server and recorded in the activity log. This screen changes the library borrowing policy; database connection credentials and other deployment secrets remain in <code>config.php</code> and should be managed by the developer/server administrator.</p></div></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
