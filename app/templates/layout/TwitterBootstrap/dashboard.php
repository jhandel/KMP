<?php

/**
 * @var \Cake\View\View $this
 */

use Cake\Core\Configure;

$user = $this->request->getAttribute("identity");

$this->Html->css("BootstrapUI.dashboard", ["block" => true]);
$this->prepend(
    "tb_body_attrs",
    ' class="' .
        implode(" ", [
            h($this->request->getParam("controller")),
            h($this->request->getParam("action")),
        ]) .
        '" ',
);
$this->start("tb_body_start");
?>

<body <?= $this->fetch("tb_body_attrs") ?>>
    <header class="navbar navbar-dark sticky-top bg-dark flex-md-nowrap p-0 shadow">
        <div class="navbar-brand col-md-3 col-lg-2 me-0 px-3">
            <?= $this->Html->image(Configure::read("App.appGraphic"), [
                "alt" => "Logo",
                "height" => "24",
                "class" => "d-inline-block mb-1",
            ]) ?>
            <span class="fs-5"><?= h(Configure::read("App.title")) ?></span>
        </div>
        <span class="w-100"></span>
        <ul class="navbar-nav px-3">
            <li class="nav-item text-nowrap">
                <?= $this->Html->link(
                    __("Sign out"),
                    ["controller" => "Members", "action" => "logout"],
                    ["class" => "nav-link"],
                ) ?>
            </li>
            <li class="nav-item text-nowrap">
                <button class="navbar-toggler position-absolute d-md-none collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </li>
        </ul>
    </header>

    <div class="container-fluid">
        <div class="row">
            <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar pt-5" style="overflow-y: auto">
                <div class="position-sticky pt-3">
                    <nav class="nav flex-column nav-underline mx-2">
                        <?php
                        $appNav = [
                            [
                                "type" => "parent",
                                "label" => "Members",
                                "icon" => "bi-people",
                                "children" => [
                                    [
                                        "type" => "link",
                                        "label" => $user->sca_name,
                                        "url" => [
                                            "controller" => "Members",
                                            "action" => "view",
                                            $user->id,
                                        ],
                                        "icon" => "bi-person-fill",
                                        "activeUrls" => [
                                            [
                                                "controller" => "Members",
                                                "action" => "view",
                                                $user->id,
                                            ],
                                            [
                                                "controller" => "Members",
                                                "action" => "viewCard",
                                                $user->id,
                                            ],
                                            [
                                                "controller" =>
                                                "AuthorizationApprovals",
                                                "action" => "myQueue",
                                                "*",
                                            ],
                                        ],
                                        "sublinks" => [
                                            [
                                                "label" => "My Auth Card",
                                                "url" => [
                                                    "controller" => "Members",
                                                    "action" => "viewCard",
                                                    $user->id,
                                                ],
                                                "icon" => "bi-person-vcard",
                                                "linkOptions" => [
                                                    "target" => "_blank",
                                                ],
                                            ],
                                            [
                                                "label" => "My Auth Queue",
                                                "url" => [
                                                    "controller" =>
                                                    "AuthorizationApprovals",
                                                    "action" => "myQueue",
                                                ],
                                                "icon" => "bi-person-fill-check",
                                            ],
                                        ],
                                    ],
                                    [
                                        "type" => "link",
                                        "label" => "Members",
                                        "url" => [
                                            "controller" => "Members",
                                            "action" => "index",
                                        ],
                                        "icon" => "bi-people",
                                        "activeUrls" => [
                                            [
                                                "controller" => "Members",
                                                "action" => "index",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Members",
                                                "action" => "add",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Members",
                                                "action" => "view",
                                                "NOT " . $user->id,
                                            ],
                                            [
                                                "controller" =>
                                                "AuthorizationApprovals",
                                                "action" => "index",
                                                "*",
                                            ],
                                            [
                                                "controller" =>
                                                "AuthorizationApprovals",
                                                "action" => "view",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Members",
                                                "action" => "importExpirationDates",
                                            ],
                                        ],
                                        "sublinks" => [
                                            [
                                                "label" => "New Member",
                                                "url" => [
                                                    "controller" => "Members",
                                                    "action" => "add",
                                                ],
                                                "icon" => "bi-person-plus",
                                            ],
                                            [
                                                "label" => "Auth Queues",
                                                "url" => [
                                                    "controller" =>
                                                    "AuthorizationApprovals",
                                                    "action" => "index",
                                                ],
                                                "icon" => "bi-card-checklist",
                                            ],
                                            [
                                                "label" => "Import Exp. Dates",
                                                "url" => [
                                                    "controller" => "Members",
                                                    "action" =>
                                                    "importExpirationDates",
                                                ],
                                                "icon" => "bi-filetype-csv",
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                "type" => "parent",
                                "label" => "Reports",
                                "icon" => "bi-backpack4",
                                "children" => [
                                    [
                                        "type" => "link",
                                        "label" => "Role Assignments",
                                        "url" => [
                                            "controller" => "Reports",
                                            "action" => "Roles",
                                        ],
                                        "icon" => "bi-ui-checks",
                                        "activeUrls" => [
                                            [
                                                "controller" => "Reports",
                                                "action" => "Roles",
                                                "*",
                                            ],
                                        ],
                                    ],
                                    [
                                        "type" => "link",
                                        "label" => "Warrent Roster",
                                        "url" => [
                                            "controller" => "Reports",
                                            "action" => "Warrants",
                                        ],
                                        "icon" => "bi-person-check-fill",
                                        "activeUrls" => [
                                            [
                                                "controller" => "Reports",
                                                "action" => "Warrants",
                                                "*",
                                            ],
                                        ],
                                    ],
                                    [
                                        "type" => "link",
                                        "label" => "Authorizations",
                                        "url" => [
                                            "controller" => "Reports",
                                            "action" => "Authorizations",
                                        ],
                                        "icon" => "bi-person-lines-fill",
                                        "activeUrls" => [
                                            [
                                                "controller" => "Reports",
                                                "action" => "Authorizations",
                                                "*",
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                "type" => "parent",
                                "label" => "System Config",
                                "icon" => "bi-database-gear",
                                "children" => [
                                    [
                                        "type" => "link",
                                        "label" => "App Settings",
                                        "url" => [
                                            "controller" => "AppSettings",
                                            "action" => "index",
                                        ],
                                        "icon" => "bi-card-list",
                                        "activeUrls" => [
                                            [
                                                "controller" => "AppSettings",
                                                "action" => "index",
                                                "*",
                                            ],
                                        ],
                                    ],
                                    [
                                        "type" => "link",
                                        "label" => "Branches",
                                        "url" => [
                                            "controller" => "Branches",
                                            "action" => "index",
                                        ],
                                        "icon" => "bi-diagram-3",
                                        "activeUrls" => [
                                            [
                                                "controller" => "Branches",
                                                "action" => "index",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Branches",
                                                "action" => "add",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Branches",
                                                "action" => "view",
                                                "*",
                                            ],
                                        ],
                                        "sublinks" => [
                                            [
                                                "label" => "New Branch",
                                                "url" => [
                                                    "controller" => "Branches",
                                                    "action" => "add",
                                                ],
                                                "icon" => "bi-plus",
                                            ],
                                        ],
                                    ],
                                    [
                                        "type" => "link",
                                        "label" => "Authorization Groups",
                                        "url" => [
                                            "controller" => "AuthorizationGroups",
                                            "action" => "index",
                                        ],
                                        "icon" => "bi-archive",
                                        "activeUrls" => [
                                            [
                                                "controller" =>
                                                "AuthorizationGroups",
                                                "action" => "index",
                                                "*",
                                            ],
                                            [
                                                "controller" =>
                                                "AuthorizationGroups",
                                                "action" => "add",
                                                "*",
                                            ],
                                            [
                                                "controller" =>
                                                "AuthorizationGroups",
                                                "action" => "view",
                                                "*",
                                            ],
                                        ],
                                        "sublinks" => [
                                            [
                                                "label" => "New Auth Group",
                                                "url" => [
                                                    "controller" =>
                                                    "AuthorizationGroups",
                                                    "action" => "add",
                                                ],
                                                "icon" => "bi-plus",
                                            ],
                                        ],
                                    ],
                                    [
                                        "type" => "link",
                                        "label" => "Authorization Types",
                                        "url" => [
                                            "controller" => "AuthorizationTypes",
                                            "action" => "index",
                                        ],
                                        "icon" => "bi-collection",
                                        "activeUrls" => [
                                            [
                                                "controller" =>
                                                "AuthorizationTypes",
                                                "action" => "index",
                                                "*",
                                            ],
                                            [
                                                "controller" =>
                                                "AuthorizationTypes",
                                                "action" => "add",
                                                "*",
                                            ],
                                            [
                                                "controller" =>
                                                "AuthorizationTypes",
                                                "action" => "view",
                                                "*",
                                            ],
                                        ],
                                        "sublinks" => [
                                            [
                                                "label" => "New Auth Type",
                                                "url" => [
                                                    "controller" =>
                                                    "AuthorizationTypes",
                                                    "action" => "add",
                                                ],
                                                "icon" => "bi-plus",
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                "type" => "parent",
                                "label" => "Security",
                                "icon" => "bi-house-lock",
                                "children" => [
                                    [
                                        "type" => "link",
                                        "label" => "Roles",
                                        "url" => [
                                            "controller" => "Roles",
                                            "action" => "index",
                                        ],
                                        "icon" => "bi-universal-access-circle",
                                        "activeUrls" => [
                                            [
                                                "controller" => "Roles",
                                                "action" => "index",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Roles",
                                                "action" => "add",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Roles",
                                                "action" => "view",
                                                "*",
                                            ],
                                        ],
                                        "sublinks" => [
                                            [
                                                "label" => "New Role",
                                                "url" => [
                                                    "controller" => "Roles",
                                                    "action" => "add",
                                                ],
                                                "icon" => "bi-plus",
                                            ],
                                        ],
                                    ],
                                    [
                                        "type" => "link",
                                        "label" => "Permissions",
                                        "url" => [
                                            "controller" => "Permissions",
                                            "action" => "index",
                                        ],
                                        "icon" => "bi-clipboard-check",
                                        "activeUrls" => [
                                            [
                                                "controller" => "Permissions",
                                                "action" => "index",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Permissions",
                                                "action" => "add",
                                                "*",
                                            ],
                                            [
                                                "controller" => "Permissions",
                                                "action" => "view",
                                                "*",
                                            ],
                                        ],
                                        "sublinks" => [
                                            [
                                                "label" => "New Permission",
                                                "url" => [
                                                    "controller" => "Permissions",
                                                    "action" => "add",
                                                ],
                                                "icon" => "bi-plus",
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ];
                        echo $this->Kmp->appNav(
                            $appNav,
                            $this->request,
                            $user,
                            $this->Html,
                        );
                        ?>
                    </nav>
                </div>
            </nav>

            <main role="main" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 my-3">
                <?php
                /** Default `flash` block. */
                if (!$this->fetch("tb_flash")) {
                    $this->start("tb_flash");
                    if (isset($this->Flash)) {
                        echo $this->Flash->render();
                    }
                    $this->end();
                }
                $this->end();
                $this->start("tb_body_end");
                ?>
            </main>
        </div>
    </div>
    <?php echo $this->fetch("modals"); ?>
</body>
<?php
$this->end();

echo $this->fetch("content");
