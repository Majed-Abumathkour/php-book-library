<?php
$localSessionPath = __DIR__ . DIRECTORY_SEPARATOR . ".sessions";
if (!is_dir($localSessionPath)) {
    @mkdir($localSessionPath, 0777, true);
}
if (is_dir($localSessionPath) && is_writable($localSessionPath)) {
    session_save_path($localSessionPath);
}
session_start();

// ---------- Helper functions ----------
function sanitize_input($value)
{
    return htmlspecialchars(trim((string) $value), ENT_QUOTES, "UTF-8", false);
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8", false);
}

function redirect_to_index()
{
    header("Location: index.php");
    exit;
}

// ---------- Static configuration ----------
$genres = ["Fiction", "Non-Fiction", "Science", "History", "Biography", "Technology"];
$currentYear = (int) date("Y");
$coverFallbackDataUri = "data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2756%27 height=%2756%27 viewBox=%270%200%2056%2056%27%3E%3Crect width=%2756%27 height=%2756%27 fill=%27%23e9ecef%27/%3E%3Ctext x=%2728%27 y=%2731%27 text-anchor=%27middle%27 font-size=%278%27 fill=%27%236c757d%27 font-family=%27Arial,sans-serif%27%3ENo%20Cover%3C/text%3E%3C/svg%3E";

// ---------- Default form state ----------
$defaultSubmittedData = [
    "title" => "",
    "author" => "",
    "genre" => "",
    "year" => "",
    "pages" => "",
    "image_url" => "",
];
$submittedData = $defaultSubmittedData;
$errors = [];

// ---------- Initial books seed ----------
if (!isset($_SESSION["books"]) || !is_array($_SESSION["books"])) {
    $_SESSION["books"] = [
        [
            "id" => 1,
            "title" => "The Pragmatic Programmer",
            "author" => "Andrew Hunt",
            "genre" => "Technology",
            "year" => 1999,
            "pages" => 352,
            "image_url" => "https://dummyimage.com/120x180/0d6efd/ffffff.jpg",
        ],
        [
            "id" => 2,
            "title" => "Sapiens",
            "author" => "Yuval Harari",
            "genre" => "History",
            "year" => 2011,
            "pages" => 443,
            "image_url" => "https://dummyimage.com/120x180/198754/ffffff.jpg",
        ],
        [
            "id" => 3,
            "title" => "Clean Code",
            "author" => "Robert Martin",
            "genre" => "Technology",
            "year" => 2008,
            "pages" => 464,
            "image_url" => "https://dummyimage.com/120x180/f59f00/ffffff.jpg",
        ],
    ];
}

$books = $_SESSION["books"];

// Replace legacy broken seed URLs in existing sessions.
$legacyCoverMap = [
    "https://images.unsplash.com/photo-1512820790803-83ca734da794.jpg" => "https://dummyimage.com/120x180/0d6efd/ffffff.jpg",
    "https://images.unsplash.com/photo-1519682337058-a94d519337bc.jpeg" => "https://dummyimage.com/120x180/198754/ffffff.jpg",
    "https://images.unsplash.com/photo-1481627834876-b7833e8f5570.png" => "https://dummyimage.com/120x180/f59f00/ffffff.jpg",
];
$didCoverMigration = false;
foreach ($books as &$book) {
    $currentCover = (string) ($book["image_url"] ?? "");
    if (isset($legacyCoverMap[$currentCover])) {
        $book["image_url"] = $legacyCoverMap[$currentCover];
        $didCoverMigration = true;
    }
}
unset($book);
if ($didCoverMigration) {
    $_SESSION["books"] = $books;
}

// ---------- Flash success message ----------
$successMessage = "";
if (isset($_SESSION["success"])) {
    $successMessage = (string) $_SESSION["success"];
    unset($_SESSION["success"]);
}

// ---------- Optional search and sort state ----------
$searchTerm = trim((string) ($_GET["q"] ?? ""));
$allowedSortColumns = ["id", "title", "author", "genre", "year", "pages"];
$sortBy = trim((string) ($_GET["sort"] ?? "id"));
$sortDir = strtolower(trim((string) ($_GET["dir"] ?? "asc")));

if (!in_array($sortBy, $allowedSortColumns, true)) {
    $sortBy = "id";
}
if ($sortDir !== "desc") {
    $sortDir = "asc";
}

// ---------- Edit mode from query parameter ----------
$isEditMode = false;
$editingBookId = null;
$editingBook = null;
$editIdQuery = trim((string) ($_GET["edit_id"] ?? ""));

if ($editIdQuery !== "" && ctype_digit($editIdQuery)) {
    $candidateId = (int) $editIdQuery;
    foreach ($books as $book) {
        if ((int) $book["id"] === $candidateId) {
            $isEditMode = true;
            $editingBookId = $candidateId;
            $editingBook = $book;
            $submittedData = [
                "title" => (string) $book["title"],
                "author" => (string) $book["author"],
                "genre" => (string) $book["genre"],
                "year" => (string) $book["year"],
                "pages" => (string) $book["pages"],
                "image_url" => (string) ($book["image_url"] ?? ""),
            ];
            break;
        }
    }
}

// ---------- POST handling (add / update / delete) ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = sanitize_input($_POST["action"] ?? "add");

    if ($action === "delete") {
        $deleteIdRaw = sanitize_input($_POST["delete_id"] ?? "");
        if ($deleteIdRaw !== "" && ctype_digit($deleteIdRaw)) {
            $deleteId = (int) $deleteIdRaw;
            $books = array_values(array_filter($books, function ($book) use ($deleteId) {
                return (int) $book["id"] !== $deleteId;
            }));
            $_SESSION["books"] = $books;
            $_SESSION["success"] = "Book deleted successfully.";
        }
        redirect_to_index();
    }

    $isUpdating = $action === "update";
    $editIdPostRaw = sanitize_input($_POST["edit_id"] ?? "");

    if ($isUpdating && $editIdPostRaw !== "" && ctype_digit($editIdPostRaw)) {
        $isEditMode = true;
        $editingBookId = (int) $editIdPostRaw;
    } elseif ($isUpdating) {
        $errors["general"] = "Invalid update request.";
    }

    // Store sanitized values first for re-population.
    $submittedData = [
        "title" => sanitize_input($_POST["title"] ?? ""),
        "author" => sanitize_input($_POST["author"] ?? ""),
        "genre" => sanitize_input($_POST["genre"] ?? ""),
        "year" => sanitize_input($_POST["year"] ?? ""),
        "pages" => sanitize_input($_POST["pages"] ?? ""),
        "image_url" => sanitize_input($_POST["image_url"] ?? ""),
    ];

    // ---------- Field-level validation ----------
    if ($submittedData["title"] === "") {
        $errors["title"] = "Title is required.";
    } elseif (strlen($submittedData["title"]) < 3 || strlen($submittedData["title"]) > 120) {
        $errors["title"] = "Title must be between 3 and 120 characters.";
    }

    if ($submittedData["author"] === "") {
        $errors["author"] = "Author is required.";
    } else {
        $authorWords = preg_split("/\s+/", $submittedData["author"]) ?: [];
        $authorWords = array_filter($authorWords, function ($word) {
            return $word !== "";
        });
        if (count($authorWords) < 2) {
            $errors["author"] = "Author must contain at least two words.";
        }
    }

    if ($submittedData["genre"] === "") {
        $errors["genre"] = "Genre is required.";
    } elseif (!in_array($submittedData["genre"], $genres, true)) {
        $errors["genre"] = "Selected genre is not allowed.";
    }

    if ($submittedData["year"] === "") {
        $errors["year"] = "Year is required.";
    } elseif (!preg_match("/^\d{4}$/", $submittedData["year"])) {
        $errors["year"] = "Year must be a 4-digit integer.";
    } else {
        $yearValue = (int) $submittedData["year"];
        if ($yearValue < 1000 || $yearValue > $currentYear) {
            $errors["year"] = "Year must be between 1000 and " . $currentYear . ".";
        }
    }

    if ($submittedData["pages"] === "") {
        $errors["pages"] = "Pages is required.";
    } elseif (!ctype_digit($submittedData["pages"]) || (int) $submittedData["pages"] <= 0) {
        $errors["pages"] = "Pages must be a positive integer greater than 0.";
    }

    // Optional challenge field validation.
    if ($submittedData["image_url"] !== "") {
        $imagePath = parse_url($submittedData["image_url"], PHP_URL_PATH);
        if (!is_string($imagePath) || !preg_match("/\.(jpg|jpeg|png|gif)$/i", $imagePath)) {
            $errors["image_url"] = "Cover URL must end with .jpg, .jpeg, .png, or .gif.";
        }
    }

    // ---------- Save changes when validation passes ----------
    if (empty($errors)) {
        if ($isUpdating) {
            $bookUpdated = false;
            foreach ($books as &$book) {
                if ((int) $book["id"] === (int) $editingBookId) {
                    $book["title"] = $submittedData["title"];
                    $book["author"] = $submittedData["author"];
                    $book["genre"] = $submittedData["genre"];
                    $book["year"] = (int) $submittedData["year"];
                    $book["pages"] = (int) $submittedData["pages"];
                    $book["image_url"] = $submittedData["image_url"];
                    $bookUpdated = true;
                    break;
                }
            }
            unset($book);

            if ($bookUpdated) {
                $_SESSION["books"] = $books;
                $submittedData = $defaultSubmittedData;
                $_SESSION["success"] = "Book updated successfully.";
                redirect_to_index();
            }
            $errors["general"] = "Book not found for update.";
        } else {
            $maxId = 0;
            foreach ($books as $book) {
                $bookId = (int) ($book["id"] ?? 0);
                if ($bookId > $maxId) {
                    $maxId = $bookId;
                }
            }
            $newId = $maxId + 1;

            $books[] = [
                "id" => $newId,
                "title" => $submittedData["title"],
                "author" => $submittedData["author"],
                "genre" => $submittedData["genre"],
                "year" => (int) $submittedData["year"],
                "pages" => (int) $submittedData["pages"],
                "image_url" => $submittedData["image_url"],
            ];

            $_SESSION["books"] = $books;
            $submittedData = $defaultSubmittedData;
            $_SESSION["success"] = "Book added successfully.";
            redirect_to_index();
        }
    }
}

// ---------- Search/filter using a loop + stripos ----------
$displayBooks = [];
if ($searchTerm !== "") {
    foreach ($books as $book) {
        $titleValue = (string) ($book["title"] ?? "");
        $authorValue = (string) ($book["author"] ?? "");
        if (stripos($titleValue, $searchTerm) !== false || stripos($authorValue, $searchTerm) !== false) {
            $displayBooks[] = $book;
        }
    }
} else {
    $displayBooks = $books;
}

// ---------- Sort table using usort ----------
usort($displayBooks, function ($a, $b) use ($sortBy, $sortDir) {
    $aValue = $a[$sortBy] ?? "";
    $bValue = $b[$sortBy] ?? "";

    if (in_array($sortBy, ["id", "year", "pages"], true)) {
        $comparison = (int) $aValue <=> (int) $bValue;
    } else {
        $comparison = strcasecmp((string) $aValue, (string) $bValue);
    }

    return $sortDir === "desc" ? -$comparison : $comparison;
});

// ---------- Helper URLs for sortable headers ----------
function sort_link($column, $label, $sortBy, $sortDir, $searchTerm)
{
    $nextDir = ($sortBy === $column && $sortDir === "asc") ? "desc" : "asc";
    $params = ["sort" => $column, "dir" => $nextDir];
    if ($searchTerm !== "") {
        $params["q"] = $searchTerm;
    }
    $arrow = "";
    if ($sortBy === $column) {
        $arrow = $sortDir === "asc" ? " ▲" : " ▼";
    }
    $url = "index.php?" . http_build_query($params);

    return "<a class=\"text-decoration-none text-dark\" href=\"" . h($url) . "\">" . h($label . $arrow) . "</a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Book Library</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body class="bg-light">
<div class="container py-4">
    <h1 class="mb-4">Personal Book Library</h1>

    <?php if ($successMessage !== ""): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= h($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3"><?= $isEditMode ? "Edit Book" : "Add New Book" ?></h2>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger" role="alert">
                            Please fix the errors below and try again.
                        </div>
                    <?php endif; ?>

                    <form method="post" novalidate>
                        <input type="hidden" name="action" value="<?= $isEditMode ? "update" : "add" ?>">
                        <?php if ($isEditMode && $editingBookId !== null): ?>
                            <input type="hidden" name="edit_id" value="<?= h((string) $editingBookId) ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input
                                type="text"
                                class="form-control <?= isset($errors["title"]) ? "is-invalid" : "" ?>"
                                id="title"
                                name="title"
                                value="<?= h($submittedData["title"]) ?>"
                            >
                            <?php if (isset($errors["title"])): ?>
                                <div class="invalid-feedback"><?= h($errors["title"]) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="author" class="form-label">Author</label>
                            <input
                                type="text"
                                class="form-control <?= isset($errors["author"]) ? "is-invalid" : "" ?>"
                                id="author"
                                name="author"
                                value="<?= h($submittedData["author"]) ?>"
                            >
                            <?php if (isset($errors["author"])): ?>
                                <div class="invalid-feedback"><?= h($errors["author"]) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="genre" class="form-label">Genre</label>
                            <select
                                class="form-control <?= isset($errors["genre"]) ? "is-invalid" : "" ?>"
                                id="genre"
                                name="genre"
                            >
                                <option value="">Select genre</option>
                                <?php foreach ($genres as $genre): ?>
                                    <option value="<?= h($genre) ?>" <?= $submittedData["genre"] === $genre ? "selected" : "" ?>>
                                        <?= h($genre) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors["genre"])): ?>
                                <div class="invalid-feedback"><?= h($errors["genre"]) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="year" class="form-label">Year</label>
                            <input
                                type="text"
                                class="form-control <?= isset($errors["year"]) ? "is-invalid" : "" ?>"
                                id="year"
                                name="year"
                                value="<?= h($submittedData["year"]) ?>"
                            >
                            <?php if (isset($errors["year"])): ?>
                                <div class="invalid-feedback"><?= h($errors["year"]) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="pages" class="form-label">Pages</label>
                            <input
                                type="text"
                                class="form-control <?= isset($errors["pages"]) ? "is-invalid" : "" ?>"
                                id="pages"
                                name="pages"
                                value="<?= h($submittedData["pages"]) ?>"
                            >
                            <?php if (isset($errors["pages"])): ?>
                                <div class="invalid-feedback"><?= h($errors["pages"]) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="image_url" class="form-label">Cover URL (Optional)</label>
                            <input
                                type="text"
                                class="form-control <?= isset($errors["image_url"]) ? "is-invalid" : "" ?>"
                                id="image_url"
                                name="image_url"
                                value="<?= h($submittedData["image_url"]) ?>"
                            >
                            <?php if (isset($errors["image_url"])): ?>
                                <div class="invalid-feedback"><?= h($errors["image_url"]) ?></div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <?= $isEditMode ? "Update Book" : "Add Book" ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h2 class="h5 mb-0">Library Books</h2>
                        <form method="get" class="d-flex gap-2">
                            <input
                                type="text"
                                class="form-control"
                                name="q"
                                placeholder="Search title or author"
                                value="<?= h($searchTerm) ?>"
                            >
                            <?php if ($sortBy !== ""): ?>
                                <input type="hidden" name="sort" value="<?= h($sortBy) ?>">
                            <?php endif; ?>
                            <?php if ($sortDir !== ""): ?>
                                <input type="hidden" name="dir" value="<?= h($sortDir) ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn btn-outline-secondary">Search</button>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered align-middle">
                            <thead>
                                <tr>
                                    <th><?= sort_link("id", "#", $sortBy, $sortDir, $searchTerm) ?></th>
                                    <th><?= sort_link("title", "Title", $sortBy, $sortDir, $searchTerm) ?></th>
                                    <th><?= sort_link("author", "Author", $sortBy, $sortDir, $searchTerm) ?></th>
                                    <th><?= sort_link("genre", "Genre", $sortBy, $sortDir, $searchTerm) ?></th>
                                    <th><?= sort_link("year", "Year", $sortBy, $sortDir, $searchTerm) ?></th>
                                    <th><?= sort_link("pages", "Pages", $sortBy, $sortDir, $searchTerm) ?></th>
                                    <th>Cover</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($displayBooks)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">No books found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($displayBooks as $book): ?>
                                        <?php
                                        $bookId = (int) $book["id"];
                                        $bookTitle = (string) $book["title"];
                                        $coverUrl = (string) ($book["image_url"] ?? "");
                                        ?>
                                        <tr>
                                            <td><?= h((string) $bookId) ?></td>
                                            <td><?= h($bookTitle) ?></td>
                                            <td><?= h((string) $book["author"]) ?></td>
                                            <td><?= h((string) $book["genre"]) ?></td>
                                            <td><?= h((string) ((int) $book["year"])) ?></td>
                                            <td><?= h((string) ((int) $book["pages"])) ?></td>
                                            <td>
                                                <?php if ($coverUrl !== ""): ?>
                                                    <img src="<?= h($coverUrl) ?>" alt="Book Cover" class="img-thumbnail" style="width: 56px; height: 56px; object-fit: cover;" onerror="this.onerror=null;this.src='<?= h($coverFallbackDataUri) ?>';">
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <a href="index.php?edit_id=<?= h((string) $bookId) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteModal"
                                                    data-book-id="<?= h((string) $bookId) ?>"
                                                    data-book-title="<?= h($bookTitle) ?>"
                                                >
                                                    Delete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden delete form -->
<form id="deleteForm" method="post" class="d-none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="delete_id" id="delete_id" value="">
</form>

<!-- Bootstrap delete confirmation modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="deleteBookTitle"></strong>?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, Delete</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    var deleteModal = document.getElementById("deleteModal");
    var deleteIdInput = document.getElementById("delete_id");
    var deleteBookTitle = document.getElementById("deleteBookTitle");
    var confirmDeleteBtn = document.getElementById("confirmDeleteBtn");
    var deleteForm = document.getElementById("deleteForm");

    deleteModal.addEventListener("show.bs.modal", function (event) {
        var triggerButton = event.relatedTarget;
        var bookId = triggerButton.getAttribute("data-book-id");
        var bookTitle = triggerButton.getAttribute("data-book-title");

        deleteIdInput.value = bookId;
        deleteBookTitle.textContent = bookTitle;
    });

    confirmDeleteBtn.addEventListener("click", function () {
        deleteForm.submit();
    });
});
</script>
</body>
</html>
