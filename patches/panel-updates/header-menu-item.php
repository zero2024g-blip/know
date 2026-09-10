<?php /*
 * Menu item for the key-renewal section, WITH an icon.
 *
 * Paste this <li> into app/Views/Layout/Header.php, in the admin dropdown,
 * right AFTER the "Deleted Keys" item (that is where you placed it). It matches
 * the exact structure of the sibling items (icon + active-state highlight), so
 * "Renew keys" now shows the circular-arrow icon like the others.
 */ ?>
<li>
    <a class="dropdown-item <?= url_is('admin/keys/renew') ? 'active' : '' ?>" href="<?= site_url('admin/keys/renew') ?>">
        <i class="bi bi-arrow-clockwise"></i> Renew keys
    </a>
</li>
