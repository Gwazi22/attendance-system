<?php
$required_role = 'admin';
require_once "auth_check.php";

$error = "";
$success = "";
$last_action = ""; // which form was submitted — decides which panel to reopen on reload

// Handle: add a new user (student, lecturer, or admin)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_user"])) {
    $last_action = "add_user";
    $full_name = trim($_POST["full_name"] ?? "");
    $email     = trim($_POST["email"] ?? "");
    $password  = $_POST["password"] ?? "";
    $role      = $_POST["role"] ?? "";
    $matric_no = trim($_POST["matric_number"] ?? "");

    $valid_roles = ["student", "lecturer", "admin"];

    if (empty($full_name) || empty($email) || empty($password) || !in_array($role, $valid_roles, true)) {
        $error = "Full name, email, password, and a valid role are required.";
    } elseif ($role === "student" && empty($matric_no)) {
        $error = "Matric number is required for a student account.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM users WHERE email = ?" . ($role === "student" ? " OR matric_number = ?" : ""));
        if ($role === "student") {
            $check->bind_param("ss", $email, $matric_no);
        } else {
            $check->bind_param("s", $email);
        }
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "A user with that email" . ($role === "student" ? " or matric number" : "") . " already exists.";
        } else {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $matric_value = $role === "student" ? $matric_no : null;

            $insert = $conn->prepare("INSERT INTO users (full_name, email, password_hash, role, matric_number) VALUES (?, ?, ?, ?, ?)");
            $insert->bind_param("sssss", $full_name, $email, $password_hash, $role, $matric_value);

            if ($insert->execute()) {
                $success = ucfirst($role) . " account created for " . $full_name . ".";
            } else {
                $error = "Could not create the account. Please try again.";
            }
            $insert->close();
        }
        $check->close();
    }
}

// Handle: reset a user's password. Students and lecturers have no
// self-service "forgot password" flow — the dashboard just tells them to
// contact an administrator, and this is the admin side of that request.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["reset_password"])) {
    $last_action = "reset_password";
    $target_user_id = (int)($_POST["user_id"] ?? 0);
    $new_password   = $_POST["new_password"] ?? "";

    if ($target_user_id <= 0) {
        $error = "No user selected.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters.";
    } else {
        $target_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
        $target_stmt->bind_param("i", $target_user_id);
        $target_stmt->execute();
        $target_row = $target_stmt->get_result()->fetch_assoc();
        $target_stmt->close();

        if (!$target_row) {
            $error = "That user no longer exists.";
        } else {
            $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $update = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $update->bind_param("si", $new_hash, $target_user_id);
            if ($update->execute()) {
                $success = "Password reset for " . $target_row['full_name'] . ". Share the new password with them securely.";
            } else {
                $error = "Could not reset the password. Please try again.";
            }
            $update->close();
        }
    }
}

// --- Filters: role tab + search ---
$role_filter = $_GET['role'] ?? 'all';
if (!in_array($role_filter, ['all', 'student', 'lecturer', 'admin'], true)) {
    $role_filter = 'all';
}
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
$types = "";
if ($role_filter !== 'all') {
    $where[] = "role = ?";
    $params[] = $role_filter;
    $types .= "s";
}
if ($search !== '') {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR matric_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sss";
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

function bind_params_dynamic($stmt, $types, $params) {
    $refs = [];
    foreach ($params as $key => $value) { $refs[$key] = &$params[$key]; }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Total count for pagination
$count_sql = "SELECT COUNT(*) AS c FROM users $where_sql";
if ($params) {
    $count_stmt = $conn->prepare($count_sql);
    bind_params_dynamic($count_stmt, $types, $params);
    $count_stmt->execute();
    $total_rows = (int)$count_stmt->get_result()->fetch_assoc()['c'];
    $count_stmt->close();
} else {
    $total_rows = (int)$conn->query($count_sql)->fetch_assoc()['c'];
}

$per_page = 10;
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = max(1, min($total_pages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $per_page;

$list_sql = "SELECT user_id, full_name, email, matric_number, role FROM users $where_sql ORDER BY full_name ASC LIMIT ? OFFSET ?";
$list_params = $params;
$list_params[] = $per_page;
$list_params[] = $offset;
$list_types = $types . "ii";

$list_stmt = $conn->prepare($list_sql);
bind_params_dynamic($list_stmt, $list_types, $list_params);
$list_stmt->execute();
$users = [];
$result = $list_stmt->get_result();
while ($row = $result->fetch_assoc()) { $users[] = $row; }
$list_stmt->close();

function qs($overrides = []) {
    $base = ['role' => $_GET['role'] ?? 'all', 'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? 1];
    $merged = array_merge($base, $overrides);
    return http_build_query(array_filter($merged, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users · AttendX</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css">
    <script src="assets/js/theme.js"></script>
</head>
<body>

<div class="field-bg" aria-hidden="true">
    <div class="glow a"></div>
    <div class="glow b"></div>
    <div class="grid"></div>
</div>

<div id="toastStack"></div>

<div class="shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="logo-mark size-sm">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 2.5 L21.5 20.5 H2.5 L12 2.5Z" fill="white"/>
                    <path d="M8.7 14.3 L11 16.8 L15.3 10.1" stroke="var(--blue-600)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </svg>
            </div>
            <div class="wordmark size-sm">AttendX</div>
        </div>

        <nav class="sidebar-nav">
            <a href="admin_dashboard.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>
                Dashboard
            </a>
            <a href="admin_users.php" class="active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><circle cx="18" cy="8.5" r="2.3"/><path d="M16.5 14.3c2.6.4 4.5 2.2 4.5 5.1"/></svg>
                Users
            </a>
            <span class="nav-soon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
                Courses
                <span class="soon-chip">SOON</span>
            </span>
            <span class="nav-soon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                Attendance
                <span class="soon-chip">SOON</span>
            </span>
            <a href="admin_reports.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 17h4v4H3zM10 10h4v11h-4zM17 4h4v17h-4z"/></svg>
                Reports
            </a>
            <span class="nav-soon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 13.5a7.4 7.4 0 0 0 0-3l2-1.6-2-3.4-2.4.8a7.6 7.6 0 0 0-2.6-1.5L14 2h-4l-.4 2.4a7.6 7.6 0 0 0-2.6 1.5l-2.4-.8-2 3.4 2 1.6a7.4 7.4 0 0 0 0 3l-2 1.6 2 3.4 2.4-.8a7.6 7.6 0 0 0 2.6 1.5L10 22h4l.4-2.4a7.6 7.6 0 0 0 2.6-1.5l2.4.8 2-3.4-2-1.6Z"/></svg>
                Settings
                <span class="soon-chip">SOON</span>
            </span>
        </nav>

        <div class="sidebar-foot">
            <div class="sidebar-user">
                <span class="name"><?= htmlspecialchars($_SESSION["full_name"]) ?></span>
                <span class="role">System Administrator</span>
            </div>
            <a href="logout.php" class="btn btn-outline btn-sm w-full">Logout</a>
        </div>
    </aside>

    <div class="main">
        <div class="dash-topbar">
            <div class="greeting">
                <h1>Manage Users</h1>
                <p>Add and review student, lecturer, and admin accounts.</p>
            </div>
            <div class="dash-topbar-right">
                <button type="button" class="btn btn-primary btn-sm" id="toggleAddUser">+ Add User</button>
                <button type="button" class="theme-switch" id="themeSwitch" data-theme="light" aria-label="Toggle day mode" aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M21.5 14.5A9.5 9.5 0 1 1 9.5 2.5a7.5 7.5 0 0 0 12 12Z"/></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/></svg>
                </button>
            </div>
        </div>

        <div class="content">

            <?php if ($error !== ""): ?>
                <div class="alert alert-danger"><span style="margin-right:auto;"><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>
            <?php if ($success !== ""): ?>
                <div class="alert alert-success"><span style="margin-right:auto;"><?= htmlspecialchars($success) ?></span></div>
            <?php endif; ?>

            <div class="panel add-panel" id="addUserPanel" style="padding-bottom:20px;">
                <div class="panel-head"><h3>Add a new user</h3></div>

                <form method="POST" action="admin_users.php<?= $role_filter !== 'all' || $search !== '' ? '?' . qs() : '' ?>" class="form-grid cols-session">
                    <div>
                        <label>Full Name</label>
                        <input type="text" name="full_name" required>
                    </div>
                    <div>
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>
                    <div>
                        <label>Role</label>
                        <select name="role" id="roleSelect" required>
                            <option value="student">Student</option>
                            <option value="lecturer">Lecturer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div id="matricField">
                        <label>Matric Number</label>
                        <input type="text" name="matric_number" placeholder="Required for students">
                    </div>
                    <div class="span-2">
                        <label>Password</label>
                        <input type="password" name="password" minlength="6" required>
                    </div>
                    <div class="span-2">
                        <button type="submit" name="add_user" class="btn btn-primary">Create Account</button>
                    </div>
                </form>
            </div>

            <div class="panel add-panel" id="resetPasswordPanel" style="padding-bottom:20px;">
                <div class="panel-head"><h3>Reset password for <span id="resetTargetName">—</span></h3></div>
                <form method="POST" action="admin_users.php<?= $role_filter !== 'all' || $search !== '' ? '?' . qs() : '' ?>" class="form-grid cols-session">
                    <input type="hidden" name="user_id" id="resetUserId" value="">
                    <div class="span-2">
                        <label>New Password</label>
                        <input type="password" name="new_password" minlength="6" required placeholder="At least 6 characters">
                    </div>
                    <div class="span-2" style="display:flex; gap:10px;">
                        <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                        <button type="button" class="btn btn-outline" id="cancelResetBtn">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="panel" style="padding-bottom:10px;">
                <div class="tabs">
                    <a href="?<?= qs(['role' => 'all', 'page' => 1]) ?>" class="<?= $role_filter === 'all' ? 'active' : '' ?>">All</a>
                    <a href="?<?= qs(['role' => 'student', 'page' => 1]) ?>" class="<?= $role_filter === 'student' ? 'active' : '' ?>">Students</a>
                    <a href="?<?= qs(['role' => 'lecturer', 'page' => 1]) ?>" class="<?= $role_filter === 'lecturer' ? 'active' : '' ?>">Lecturers</a>
                    <a href="?<?= qs(['role' => 'admin', 'page' => 1]) ?>" class="<?= $role_filter === 'admin' ? 'active' : '' ?>">Admins</a>
                </div>

                <form method="GET" action="admin_users.php" class="toolbar">
                    <input type="hidden" name="role" value="<?= htmlspecialchars($role_filter) ?>">
                    <div class="search-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email">
                    </div>
                    <button type="submit" class="btn btn-outline btn-sm">Search</button>
                </form>

                <?php if (empty($users)): ?>
                    <p class="muted" style="padding-bottom:16px;">No users match that search.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Name</th><th>Matric / Staff No.</th><th>Role</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($users as $u):
                                    $role_chip = ['student' => 'chip-secondary', 'lecturer' => 'chip-primary', 'admin' => 'chip-success'][$u['role']] ?? 'chip-secondary';
                                ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($u['full_name']) ?>
                                            <div class="muted small"><?= htmlspecialchars($u['email']) ?></div>
                                        </td>
                                        <td><?= $u['matric_number'] ? htmlspecialchars($u['matric_number']) : '<span class="muted">—</span>' ?></td>
                                        <td><span class="chip <?= $role_chip ?>"><?= ucfirst($u['role']) ?></span></td>
                                        <td><span class="chip chip-success">Active</span></td>
                                        <td>
                                            <button type="button" class="btn-icon js-reset-password"
                                                    data-user-id="<?= (int)$u['user_id'] ?>"
                                                    data-user-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                                                Reset Password
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <a href="?<?= qs(['page' => max(1, $page - 1)]) ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
                            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                <a href="?<?= qs(['page' => $p]) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                            <?php endfor; ?>
                            <a href="?<?= qs(['page' => min($total_pages, $page + 1)]) ?>" class="<?= $page >= $total_pages ? 'disabled' : '' ?>">›</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
const addPanel = document.getElementById("addUserPanel");
const toggleBtn = document.getElementById("toggleAddUser");
const resetPanel = document.getElementById("resetPasswordPanel");

toggleBtn.addEventListener("click", function () {
    resetPanel.classList.remove("open");
    addPanel.classList.toggle("open");
});

document.querySelectorAll(".js-reset-password").forEach(function (btn) {
    btn.addEventListener("click", function () {
        addPanel.classList.remove("open");
        document.getElementById("resetUserId").value = btn.dataset.userId;
        document.getElementById("resetTargetName").textContent = btn.dataset.userName;
        resetPanel.classList.add("open");
        resetPanel.scrollIntoView({ behavior: "smooth", block: "center" });
    });
});
document.getElementById("cancelResetBtn").addEventListener("click", function () {
    resetPanel.classList.remove("open");
});

<?php if ($last_action === "add_user" && ($error !== "" || $success !== "")): ?>
addPanel.classList.add("open");
<?php elseif ($last_action === "reset_password" && ($error !== "" || $success !== "")): ?>
resetPanel.classList.add("open");
<?php endif; ?>

const roleSelect = document.getElementById("roleSelect");
const matricField = document.getElementById("matricField");
function syncMatricField() {
    matricField.style.display = roleSelect.value === "student" ? "block" : "none";
}
roleSelect.addEventListener("change", syncMatricField);
syncMatricField();

<?php if ($error): ?>
document.addEventListener("DOMContentLoaded", function () { showToast(<?= json_encode($error) ?>, "danger"); });
<?php endif; ?>
<?php if ($success): ?>
document.addEventListener("DOMContentLoaded", function () { showToast(<?= json_encode($success) ?>, "success"); });
<?php endif; ?>
</script>
</body>
</html>