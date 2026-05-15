<?php
/**
 * @var \Cake\View\View $this
 */
?>
<!doctype html>
<html lang="en">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform Admin</title>
    <?= $this->Html->css('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css') ?>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <?= $this->Html->link('KMP Platform Admin', ['controller' => 'PlatformAdmin', 'action' => 'index'], ['class' => 'navbar-brand']) ?>
            <div class="navbar-nav">
                <?= $this->Html->link('Tenants', ['controller' => 'PlatformAdmin', 'action' => 'index'], ['class' => 'nav-link']) ?>
                <?= $this->Html->link('Command Catalog', ['controller' => 'PlatformAdmin', 'action' => 'commandCatalog'], ['class' => 'nav-link']) ?>
                <?= $this->Html->link('Audit', ['controller' => 'PlatformAdmin', 'action' => 'audit'], ['class' => 'nav-link']) ?>
                <?= $this->Html->link('Logout', ['controller' => 'PlatformAdmin', 'action' => 'logout'], ['class' => 'nav-link']) ?>
            </div>
        </div>
    </nav>
    <main class="container py-4">
        <?= $this->Flash->render() ?>
        <?= $this->fetch('content') ?>
    </main>
</body>
</html>
