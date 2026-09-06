<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once ROOT_PATH . '/config/db.php';
require_role('admin');

$page = 'users';
$title = 'User Management';
$heading = 'User Management';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role_id = (int) ($_POST['role_id'] ?? 0);
    $status = $_POST['status'] ?? 'Active';

    if ($action === 'add' && $full_name && $username && !empty($_POST['password'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role_id, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$full_name, $username, $hash, $role_id, $status]);
        $msg = 'User created successfully.';
    }

    if ($action === 'edit') {
        $id = (int) $_POST['id'];
        if (!empty($_POST['password'])) {
            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, password = ?, role_id = ?, status = ? WHERE id = ?");
            $stmt->execute([$full_name, $username, $hash, $role_id, $status, $id]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, role_id = ?, status = ? WHERE id = ?");
            $stmt->execute([$full_name, $username, $role_id, $status, $id]);
        }
        $msg = 'User updated successfully.';
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    if ($id !== (int) $_SESSION['u_id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
    }
    redirect('/modules/users/index.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$roles = $conn->query("SELECT * FROM roles ORDER BY role_name")->fetchAll();
$users = $conn->query("
    SELECT users.*, roles.role_name
    FROM users
    JOIN roles ON roles.id = users.role_id
    ORDER BY users.created_at DESC
")->fetchAll();

include ROOT_PATH . '/includes/header.php';
?>
<div class="grid grid-2">
    <section class="card">
        <h2><?= $edit ? 'Edit User' : 'Create User' ?></h2>
        <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
        <form class="form" method="post">
            <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= e($edit['id']) ?>"><?php endif; ?>
            <div class="field"><label>Full Name</label><input name="full_name" value="<?= e($edit['full_name'] ?? '') ?>" required></div>
            <div class="field"><label>Username</label><input name="username" value="<?= e($edit['username'] ?? '') ?>" required></div>
            <div class="field"><label>Password</label><input type="password" name="password" <?= $edit ? '' : 'required' ?>></div>
            <div class="field">
                <label>Role</label>
                <select name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role['id']) ?>" <?= ($edit['role_id'] ?? '') == $role['id'] ? 'selected' : '' ?>><?= e($role['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option <?= ($edit['status'] ?? '') === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option <?= ($edit['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Save User</button>
        </form>
    </section>

    <section class="card">
        <div class="page-head">
            <h2>Employees</h2>
            <?php if ($edit): ?><a class="btn btn-secondary" href="index.php">Cancel</a><?php endif; ?>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Role</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($users as $row): ?>
                    <tr>
                        <td><strong><?= e($row['full_name']) ?></strong><br><span class="muted">@<?= e($row['username']) ?></span></td>
                        <td><?= e($row['role_name']) ?></td>
                        <td><span class="badge <?= $row['status'] === 'Active' ? 'badge-success' : 'badge-muted' ?>"><?= e($row['status']) ?></span></td>
                        <td class="actions">
                            <a class="btn btn-secondary" href="?edit=<?= e($row['id']) ?>"><i class="bi bi-pencil"></i></a>
                            <a class="btn btn-danger" data-confirm="Delete this user?" href="?delete=<?= e($row['id']) ?>"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include ROOT_PATH . '/includes/footer.php'; ?>
