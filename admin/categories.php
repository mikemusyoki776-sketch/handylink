
<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection error. Check config/database.php and its connection variable.");
}

$conn->set_charset("utf8mb4");

if (empty($_SESSION["category_csrf"])) {
    $_SESSION["category_csrf"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["category_csrf"];
$adminName = $_SESSION["full_name"] ?? "Admin";

if (!isset($_SESSION["category_flash"])) {
    $_SESSION["category_flash"] = "";
}

function categoryEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function categoryRedirect() {
    header("Location: categories.php");
    exit();
}

function categoryUsageCount($conn, $table, $categoryId) {
    $allowedTables = ["services", "service_requests"];

    if (!in_array($table, $allowedTables, true)) {
        return 0;
    }

    $sql = "SELECT COUNT(*) AS total FROM `$table` WHERE category_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($result["total"] ?? 0);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrfToken, $submittedToken)) {
        $_SESSION["category_flash"] = "Your session token is invalid. Please try again.";
        categoryRedirect();
    }

    $action = $_POST["action"] ?? "";

    if ($action === "save") {
        $categoryId = filter_var(
            $_POST["category_id"] ?? 0,
            FILTER_VALIDATE_INT
        );

        $name = trim($_POST["name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $status = $_POST["status"] ?? "active";

        if ($name === "" || mb_strlen($name) > 150) {
            $_SESSION["category_flash"] =
                "Enter a category name of no more than 150 characters.";
            categoryRedirect();
        }

        if (mb_strlen($description) > 2000) {
            $_SESSION["category_flash"] =
                "The description must not exceed 2,000 characters.";
            categoryRedirect();
        }

        if (!in_array($status, ["active", "inactive"], true)) {
            $status = "active";
        }

        if ($categoryId > 0) {
            $check = $conn->prepare(
                "SELECT id FROM categories WHERE id = ?"
            );
            $check->bind_param("i", $categoryId);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$exists) {
                $_SESSION["category_flash"] =
                    "The category you are editing was not found.";
                categoryRedirect();
            }

            $stmt = $conn->prepare(
                "UPDATE categories
                 SET name = ?, description = ?, status = ?
                 WHERE id = ?"
            );
            $stmt->bind_param(
                "sssi",
                $name,
                $description,
                $status,
                $categoryId
            );
            $successMessage = "Category updated successfully.";
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO categories (name, description, status)
                 VALUES (?, ?, ?)"
            );
            $stmt->bind_param("sss", $name, $description, $status);
            $successMessage = "Category added successfully.";
        }

        try {
            if ($stmt->execute()) {
                $_SESSION["category_flash"] = $successMessage;
            } else {
                $_SESSION["category_flash"] =
                    "The category could not be saved.";
            }
        } catch (mysqli_sql_exception $e) {
            $_SESSION["category_flash"] =
                $e->getCode() === 1062
                    ? "A category with that name already exists."
                    : "A database error occurred while saving the category.";
        }

        $stmt->close();
        categoryRedirect();
    }

    if ($action === "toggle") {
        $categoryId = filter_var(
            $_POST["category_id"] ?? 0,
            FILTER_VALIDATE_INT
        );

        $stmt = $conn->prepare(
            "UPDATE categories
             SET status = CASE
                WHEN status = 'active' THEN 'inactive'
                ELSE 'active'
             END
             WHERE id = ?"
        );
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();

        $_SESSION["category_flash"] = $stmt->affected_rows > 0
            ? "Category status updated."
            : "No category status was changed.";

        $stmt->close();
        categoryRedirect();
    }

    if ($action === "delete") {
        $categoryId = filter_var(
            $_POST["category_id"] ?? 0,
            FILTER_VALIDATE_INT
        );

        if (!$categoryId || $categoryId < 1) {
            $_SESSION["category_flash"] = "Invalid category selected.";
            categoryRedirect();
        }

        $servicesCount = categoryUsageCount(
            $conn,
            "services",
            $categoryId
        );

        $requestsCount = categoryUsageCount(
            $conn,
            "service_requests",
            $categoryId
        );

        if ($servicesCount > 0 || $requestsCount > 0) {
            $_SESSION["category_flash"] =
                "This category is being used by existing services or requests. " .
                "Deactivate it instead to preserve existing records.";
            categoryRedirect();
        }

        $stmt = $conn->prepare(
            "DELETE FROM categories WHERE id = ?"
        );
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();

        $_SESSION["category_flash"] = $stmt->affected_rows > 0
            ? "Category deleted successfully."
            : "Category not found.";

        $stmt->close();
        categoryRedirect();
    }

    $_SESSION["category_flash"] = "Invalid action.";
    categoryRedirect();
}

$flashMessage = $_SESSION["category_flash"];
$_SESSION["category_flash"] = "";

$editCategory = null;
$editId = filter_input(INPUT_GET, "edit", FILTER_VALIDATE_INT);

if ($editId && $editId > 0) {
    $stmt = $conn->prepare(
        "SELECT id, name, description, status
         FROM categories
         WHERE id = ?"
    );
    $stmt->bind_param("i", $editId);
    $stmt->execute();

    $editCategory = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$editCategory) {
        $flashMessage = "The requested category was not found.";
    }
}

$search = trim($_GET["search"] ?? "");

if ($search !== "") {
    $searchTerm = "%" . $search . "%";

    $stmt = $conn->prepare(
        "SELECT c.id, c.name, c.description, c.image,
                c.status, c.created_at,
                (SELECT COUNT(*) FROM services s
                 WHERE s.category_id = c.id) AS service_count,
                (SELECT COUNT(*) FROM service_requests r
                 WHERE r.category_id = c.id) AS request_count
         FROM categories c
         WHERE c.name LIKE ? OR c.description LIKE ?
         ORDER BY c.id DESC"
    );
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
} else {
    $stmt = $conn->prepare(
        "SELECT c.id, c.name, c.description, c.image,
                c.status, c.created_at,
                (SELECT COUNT(*) FROM services s
                 WHERE s.category_id = c.id) AS service_count,
                (SELECT COUNT(*) FROM service_requests r
                 WHERE r.category_id = c.id) AS request_count
         FROM categories c
         ORDER BY c.id DESC"
    );
}

$stmt->execute();
$categories = $stmt->get_result();

$totalCategories = 0;
$activeCategories = 0;
$inactiveCategories = 0;

$summaryResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'active'), 0) AS active_total,
        COALESCE(SUM(status = 'inactive'), 0) AS inactive_total
     FROM categories"
);

if ($summaryResult) {
    $summary = $summaryResult->fetch_assoc();
    $totalCategories = (int)$summary["total"];
    $activeCategories = (int)$summary["active_total"];
    $inactiveCategories = (int)$summary["inactive_total"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories | HandyLink Admin</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body>

<header class="page-header">
    <a href="dashboard.php" class="page-brand">
        <img src="../assets/images/handylink-logo.png"
             alt="HandyLink logo">
        <span>
            <strong>HandyLink</strong>
            <small>Admin Portal</small>
        </span>
    </a>

    <div class="header-actions">
        <span class="admin-name">
            <i class="fa-solid fa-user-shield"></i>
            <?= categoryEscape($adminName) ?>
        </span>
        <a href="dashboard.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i>
            Dashboard
        </a>
    </div>
</header>

<main class="page-container">

    <div class="page-heading">
        <div>
            <p class="eyebrow">SERVICE MANAGEMENT</p>
            <h1>Service Categories</h1>
            <p class="page-description">
                Organize the types of services available on HandyLink.
            </p>
        </div>
    </div>

    <?php if ($flashMessage !== ""): ?>
        <div class="notice" role="status">
            <i class="fa-solid fa-circle-info"></i>
            <?= categoryEscape($flashMessage) ?>
        </div>
    <?php endif; ?>

    <section class="summary-grid">
        <div class="summary-item">
            <span class="summary-icon"><i class="fa-solid fa-layer-group"></i></span>
            <div>
                <p>Total Categories</p>
                <strong><?= $totalCategories ?></strong>
            </div>
        </div>

        <div class="summary-item">
            <span class="summary-icon active-icon"><i class="fa-solid fa-circle-check"></i></span>
            <div>
                <p>Active Categories</p>
                <strong><?= $activeCategories ?></strong>
            </div>
        </div>

        <div class="summary-item">
            <span class="summary-icon inactive-icon"><i class="fa-solid fa-circle-pause"></i></span>
            <div>
                <p>Inactive Categories</p>
                <strong><?= $inactiveCategories ?></strong>
            </div>
        </div>
    </section>

    <div class="content-grid">

        <section class="panel category-form-panel">
            <div class="panel-heading">
                <div>
                    <h2>
                        <?= $editCategory ? "Edit Category" : "Add Category" ?>
                    </h2>
                    <p>
                        <?= $editCategory
                            ? "Update the selected category."
                            : "Create a new service category." ?>
                    </p>
                </div>
                <i class="fa-solid <?= $editCategory ? "fa-pen-to-square" : "fa-folder-plus" ?>"></i>
            </div>

            <form method="POST" action="categories.php" class="category-form">
                <input type="hidden" name="csrf_token"
                       value="<?= categoryEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="category_id"
                       value="<?= (int)($editCategory["id"] ?? 0) ?>">

                <label for="name">Category Name</label>
                <input type="text" id="name" name="name"
                       maxlength="150" required
                       placeholder="e.g. Electrical Services"
                       value="<?= categoryEscape($editCategory["name"] ?? "") ?>">

                <label for="description">Description</label>
                <textarea id="description" name="description"
                          rows="5" maxlength="2000"
                          placeholder="Describe the services in this category..."><?= categoryEscape($editCategory["description"] ?? "") ?></textarea>

                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active"
                        <?= ($editCategory["status"] ?? "active") === "active" ? "selected" : "" ?>>
                        Active
                    </option>
                    <option value="inactive"
                        <?= ($editCategory["status"] ?? "") === "inactive" ? "selected" : "" ?>>
                        Inactive
                    </option>
                </select>

                <button type="submit" class="primary-button">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <?= $editCategory ? "Save Changes" : "Add Category" ?>
                </button>

                <?php if ($editCategory): ?>
                    <a href="categories.php" class="cancel-link">
                        Cancel Editing
                    </a>
                <?php endif; ?>
            </form>
        </section>

        <section class="panel category-list-panel">
            <div class="panel-heading list-heading">
                <div>
                    <h2>Existing Categories</h2>
                    <p>Manage categories currently in your database.</p>
                </div>
                <span class="count-badge"><?= $totalCategories ?></span>
            </div>

            <form method="GET" action="categories.php" class="search-form">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="search"
                       placeholder="Search categories..."
                       value="<?= categoryEscape($search) ?>">
                <button type="submit">Search</button>
                <?php if ($search !== ""): ?>
                    <a href="categories.php" class="clear-search">Clear</a>
                <?php endif; ?>
            </form>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Usage</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($categories->num_rows === 0): ?>
                        <tr>
                            <td colspan="4" class="empty-state">
                                No categories found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while ($category = $categories->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="category-name">
                                        <span class="category-symbol">
                                            <i class="fa-solid fa-toolbox"></i>
                                        </span>
                                        <div>
                                            <strong><?= categoryEscape($category["name"]) ?></strong>
                                            <small>
                                                <?= categoryEscape($category["description"] ?: "No description added") ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>

                                <td class="usage-cell">
                                    <span><?= (int)$category["service_count"] ?> services</span>
                                    <span><?= (int)$category["request_count"] ?> requests</span>
                                </td>

                                <td>
                                    <span class="status-badge <?= $category["status"] === "active" ? "status-active" : "status-inactive" ?>">
                                        <?= categoryEscape(ucfirst($category["status"])) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-buttons">
                                        <a class="action-button edit-button"
                                           href="categories.php?edit=<?= (int)$category["id"] ?>"
                                           title="Edit category"
                                           aria-label="Edit category">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>

                                        <form method="POST" action="categories.php"
                                              class="inline-form"
                                              onsubmit="return confirm('Change the status of this category?');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= categoryEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="category_id"
                                                   value="<?= (int)$category["id"] ?>">
                                            <button type="submit"
                                                    class="action-button toggle-button"
                                                    title="Activate or deactivate category"
                                                    aria-label="Toggle category status">
                                                <i class="fa-solid <?= $category["status"] === "active" ? "fa-toggle-on" : "fa-toggle-off" ?>"></i>
                                            </button>
                                        </form>

                                        <form method="POST" action="categories.php"
                                              class="inline-form"
                                              onsubmit="return confirm('Permanently delete this category? This will only work if no services or requests use it.');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= categoryEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="category_id"
                                                   value="<?= (int)$category["id"] ?>">
                                            <button type="submit"
                                                    class="action-button delete-button"
                                                    title="Delete category"
                                                    aria-label="Delete category">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </div>
</main>

<footer class="page-footer">
    &copy; <?= date("Y") ?> HandyLink Solutions. All Rights Reserved.
</footer>

</body>
</html>
