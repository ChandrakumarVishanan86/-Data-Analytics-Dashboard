        </main>
    </div>
</div>
<script src="<?= $base ?? '' ?>assets/js/app.js"></script>
<?php foreach (($extraScripts ?? []) as $src): ?>
<script src="<?= $base ?? '' ?><?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
