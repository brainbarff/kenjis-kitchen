        </main>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= (int)@filemtime(ROOT_PATH . '/assets/js/app.js') ?: '20261118' ?>"></script>
<?php if (!empty($orderSounds)): ?>
<script src="<?= BASE_URL ?>/assets/js/order-sounds.js?v=<?= (int)@filemtime(ROOT_PATH . '/assets/js/order-sounds.js') ?: '20261118' ?>"></script>
<?php endif; ?>
<?php if (!empty($script)): ?>
<script src="<?= BASE_URL ?>/assets/js/<?= e($script) ?>"></script>
<?php endif; ?>
</body>
</html>
