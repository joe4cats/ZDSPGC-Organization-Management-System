<?php
/**
 * layout/footer.php — closes the shell and loads the shared JavaScript.
 *
 * Pages may set $EXTRA_JS to an array of file names inside assets/js/
 * (for example ['scanner.js']) and $PAGE_CHARTS to load Chart.js.
 */

declare(strict_types=1);

$EXTRA_JS = $EXTRA_JS ?? [];
$__print  = $PAGE_PRINT ?? false;
?>
<?php if (!$__print): ?>
  </div><!-- /.wrap -->
</main>
</div><!-- /.main-col -->
</div><!-- /.app-shell -->
<?php else: ?>
  </main>
  <div class="print-signatories no-print">
    <div class="sig"><span class="sig-line"></span><b>Prepared by</b></div>
    <div class="sig"><span class="sig-line"></span><b>Reviewed by</b></div>
    <div class="sig"><span class="sig-line"></span><b>Approved by</b></div>
  </div>
  <div class="print-signatories print-only">
    <div class="sig"><span class="sig-line"></span><b>Prepared by</b></div>
    <div class="sig"><span class="sig-line"></span><b>Reviewed by</b></div>
    <div class="sig"><span class="sig-line"></span><b>Approved by</b></div>
  </div>
<?php endif; ?>

<div class="toast-stack" id="toast-stack" aria-live="polite" aria-atomic="true"></div>
<div class="modal-root" id="modal-root" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-head">
      <h2 id="modal-title">Dialog</h2>
      <button class="icon-btn" type="button" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
    </div>
    <div class="modal-body" id="modal-body"></div>
    <div class="modal-foot" id="modal-foot"></div>
  </div>
</div>

<script src="<?= Helpers::e(Helpers::url('assets/js/app.js')) ?>" defer></script>
<script src="<?= Helpers::e(Helpers::url('assets/js/charts.js')) ?>" defer></script>
<?php foreach ($EXTRA_JS as $js): ?>
<script src="<?= Helpers::e(Helpers::url('assets/js/' . $js)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
