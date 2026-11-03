        </main>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=20261019_sidebar"></script>
<?php if (!empty($orderSounds)): ?>
<script src="<?= BASE_URL ?>/assets/js/order-sounds.js"></script>
<?php endif; ?>
<?php if (!empty($script)): ?>
<script src="<?= BASE_URL ?>/assets/js/<?= e($script) ?>"></script>
<?php endif; ?>
</body>
</html>
