<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// Database connection
$host = 'localhost';
$user = 'root';
$password = ''; // Your MySQL password
$database = 'Cyrus_Motor_shop';
$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle user login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = md5($_POST['password']); // Hash the password for security

    $query = "SELECT * FROM users WHERE username = ? AND password = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        if ($user['role'] === 'admin') {
            header('Location: index.php?section=admin');
        } else {
            header('Location: index.php?section=products');
        }
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}

// Handle user logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Fetch products
function getProducts($conn) {
    return $conn->query("SELECT * FROM products")->fetch_all(MYSQLI_ASSOC);
}

// Fetch product details for editing
if (isset($_GET['fetch_product']) && $_SESSION['role'] === 'admin') {
    $id = intval($_GET['fetch_product']);
    $query = "SELECT * FROM products WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    echo json_encode($product);
    exit;
}

// Add product with image upload (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product']) && $_SESSION['role'] === 'admin') {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];

    // Handle image upload
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true); // Create directory if it doesn't exist
        }

        $imageName = uniqid() . "_" . basename($_FILES['image']['name']); // Ensure unique filenames
        $imagePath = $uploadDir . $imageName;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
            $imagePath = null; // Reset if upload fails
            echo "<p class='error'>Failed to upload the image.</p>";
        }
    }

    // Save product to the database
    if ($imagePath) {
        $query = "INSERT INTO products (name, description, image, price) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('sssd', $name, $description, $imagePath, $price);
        $stmt->execute();
        header('Location: index.php?section=admin');
        exit;
    } else {
        echo "<p class='error'>Please upload a valid image file.</p>";
    }
}

// Update product details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product']) && $_SESSION['role'] === 'admin') {
    $id = intval($_POST['product_id']);
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = floatval($_POST['price']);

    // Initialize $imagePath to handle missing 'existing_image'
    $imagePath = $_POST['existing_image'] ?? null;

    // Handle image upload (optional)
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $imageName = uniqid() . "_" . basename($_FILES['image']['name']);
        $imagePath = $uploadDir . $imageName;
        move_uploaded_file($_FILES['image']['tmp_name'], $imagePath);
    }

    // Prepare and execute query
    $query = "UPDATE products SET name = ?, description = ?, image = ?, price = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('sssdi', $name, $description, $imagePath, $price, $id);

    if ($stmt->execute()) {
        // Check if it's an AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(["status" => "success", "message" => "Product updated successfully."]);
            exit;
        } else {
            // Redirect for non-AJAX request
            header("Location: index.php?section=admin");
            exit;
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update product."]);
        exit;
    }
}



// Delete product
if (isset($_GET['delete']) && $_SESSION['role'] === 'admin') {
    $id = intval($_GET['delete']);
    $query = "DELETE FROM products WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    header('Location: index.php?section=admin');
    exit;
}


// buyer side

// Add to cart (Buyer only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart']) && $_SESSION['role'] === 'buyer') {
    $product_id = $_POST['product_id'];
    $user_id = $_SESSION['user_id'];

    // Check stock availability
    $query = "SELECT stock FROM products WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();

    if ($product && $product['stock'] > 0) {
        // Deduct stock
        $updateStock = "UPDATE products SET stock = stock - 1 WHERE id = ?";
        $stmt = $conn->prepare($updateStock);
        $stmt->bind_param('i', $product_id);
        $stmt->execute();

        // Add to cart
        $query = "INSERT INTO cart (user_id, product_id) VALUES (?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ii', $user_id, $product_id);
        $stmt->execute();

        header('Location: index.php?section=products');
        exit;
    } else {
        echo "<p class='error'>Sorry, this product is out of stock.</p>";
    }
}
/// Improved Checkout Logic
function checkoutCart() {
    global $conn;

    foreach ($_SESSION['cart'] as $productId => $cartItem) {
        $stmt = $conn->prepare("SELECT quantity FROM products WHERE id = ?");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();

        if ($product['quantity'] >= $cartItem['quantity']) {
            $stmt = $conn->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
            $stmt->bind_param("ii", $cartItem['quantity'], $productId);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                unset($_SESSION['cart'][$productId]); // Remove from cart on success
            }
        } else {
            echo "Insufficient stock for product: " . htmlspecialchars($cartItem['name']);
        }
    }
}

// Handle adding to cart
// Assuming you have a function to add items to the cart
if (isset($_POST['add_to_cart'])) {
    $product_id = $_POST['product_id'];
    $user_id = $_SESSION['user_id'];

    // Insert into the cart, incrementing the quantity each time the user adds the same product
    $query = "INSERT INTO cart (user_id, product_id, quantity) 
              VALUES (?, ?, 1)
              ON DUPLICATE KEY UPDATE quantity = quantity + 1"; // If already in cart, just increase the quantity
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $user_id, $product_id);
    $stmt->execute();
}

// Handle checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout') {
    checkoutCart();
    echo "Checkout completed successfully.";
}


// Remove from cart (Buyer only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_from_cart']) && $_SESSION['role'] === 'buyer') {
    $product_id = $_POST['product_id'];
    $user_id = $_SESSION['user_id'];

    $query = "DELETE FROM cart WHERE user_id = ? AND product_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $user_id, $product_id);
    $stmt->execute();
    header('Location: index.php?section=cart');
    exit;
}

// Fetch cart items for the user
function getCartItems($conn, $user_id) {
    $query = "SELECT products.* FROM cart INNER JOIN products ON cart.product_id = products.id WHERE cart.user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

$products = getProducts($conn);
$cart_items = isset($_SESSION['user_id']) ? getCartItems($conn, $_SESSION['user_id']) : [];
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CYRUS MOTOR SHOP</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>

<header>
  <h1>CYRUS MOTOR SHOP</h1>
  <nav>
    <?php if (!isset($_SESSION['username'])): ?>
      <a href="index.php?section=login">Login</a>
    <?php else: ?>
      <a href="index.php">Home</a>
      <a href="index.php?section=products">Products</a>
      <?php if ($_SESSION['role'] === 'buyer'): ?>
        <a href="index.php?section=cart">Cart (<?php echo count($cart_items); ?>)</a>
      <?php endif; ?>
      <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="index.php?section=admin">Admin Panel</a>
      <?php endif; ?>
      <a href="index.php?logout=true">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a>
    <?php endif; ?>


  </nav>
</header>
<!-- order buyer side -->
<div id="popup-overlay" class="popup-overlay"></div>
<div id="mini-popup" class="mini-popup">
    <p id="popup-message">Your Order is Placed! It will be delivered soon!</p>
    <button onclick="closePopup()">Done</button>
    
</div>



<!-- Edit Modal -->
<div id="editModal">
    <form id="editForm" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="update_product" value="1">
        <input type="hidden" name="product_id" id="product_id">
        <input type="hidden" name="existing_image" id="existing_image">
        <input type="text" name="name" id="name" required>
        <input type="text" name="description" id="description" required>
        <input type="number" name="price" id="price" step="0.01" required>
        <input type="file" name="image" accept="image/*">
        <button type="submit">Update Product</button>
    </form>
</div>




<script>


  function showPopup(message) {
      document.getElementById('popup-message').textContent = message;
      document.getElementById('popup-overlay').style.display = 'block';
      document.getElementById('mini-popup').style.display = 'block';
  }

  function closePopup() {
      document.getElementById('popup-overlay').style.display = 'none';
      document.getElementById('mini-popup').style.display = 'none';
      window.location.href = 'index.php?section=products';
  }

  //edit button admin
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch(`index.php?fetch_product=${id}`) // Fix: Use template string with backticks
            .then(response => response.json())
            .then(data => {
                document.getElementById('product_id').value = data.id;
                document.getElementById('name').value = data.name;
                document.getElementById('description').value = data.description;
                document.getElementById('price').value = data.price;
                document.getElementById('existing_image').value = data.image;
                document.getElementById('editModal').style.display = 'block';
            });
    });
});

document.getElementById('editForm').addEventListener('submit', function(event) {
    event.preventDefault();
    const formData = new FormData(this);
    fetch('index.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }, // Add AJAX indicator
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message);
        }
    });
});



    
</script>


<?php
// Start output buffering to prevent header issues
ob_start();
$error = '';

// Define valid sections
$validSections = ['home', 'login', 'products', 'admin', 'cart', 'checkout'];

// Get the requested section, defaulting to 'home' if not provided
$section = $_GET['section'] ?? 'home';

// Check if the requested section is valid
if (!in_array($section, $validSections)) {
    // Redirect to the homepage if the section is invalid
    echo "<script>window.location.href = 'index.php';</script>";
    exit; // Ensure script execution stops after redirect
}

// Redirect to login if user is not logged in and section is not 'login'
if (!isset($_SESSION['username']) && $section !== 'login') {
    // Redirect using JavaScript (alternative to header)
    echo "<script>window.location.href = 'index.php?section=login';</script>";
    exit; // Ensure script execution stops after redirect
}

// Fetch cart items if the user is logged in
if (isset($_SESSION['user_id'])) {
    $cart_items = getCartItems($conn, $_SESSION['user_id']);
} else {
    $cart_items = [];
}

// Handle cart actions (Add, Remove, Checkout)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add to Cart
    if (isset($_POST['add_to_cart'])) {
        $product_id = $_POST['product_id'];
        addToCart($conn, $_SESSION['user_id'], $product_id);
    }

    // Remove from Cart
    if (isset($_POST['remove_from_cart'])) {
        $product_id = $_POST['product_id'];
        removeFromCart($conn, $_SESSION['user_id'], $product_id);
    }

    // Checkout
    if (isset($_POST['checkout'])) {
        checkout($conn, $_SESSION['user_id'], $cart_items);
    }
}
// Checkout function
function checkout($conn, $user_id, $cart_items) {
    // Array to keep track of the total quantity per product in the cart
    $product_quantities = [];

    // Calculate the quantity of each product in the cart (how many times each is added)
    foreach ($cart_items as $item) {
        $product_id = $item['id'];
        // If the product hasn't been counted yet, initialize it in the array
        if (!isset($product_quantities[$product_id])) {
            $product_quantities[$product_id] = 0;
        }
        // Increase the count based on how many times it's added to the cart
        $product_quantities[$product_id] +=1;
    }

    // Process each product and check if enough stock is available
    foreach ($product_quantities as $product_id => $quantity_to_deduct) {
        // Get the product details from the database
        $query = "SELECT stock FROM products WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();

        if ($product && $product['stock'] >= $quantity_to_deduct) {
            // Update product stock based on the quantity added to the cart
            $update_query = "UPDATE products SET stock = stock - ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param('ii', $quantity_to_deduct, $product_id);
            $stmt->execute();
        } else {
            // If there is not enough stock, stop checkout and display an error
            echo "Error: Not enough stock for product " . htmlspecialchars($item['name']) . ".";
            exit; // Stop checkout if there isn't enough stock
        }
    }

    // Empty the user's cart after checkout
    $query = "DELETE FROM cart WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();

    // Redirect to a confirmation page or home after checkout
    echo "<script>window.location.href = 'index.php?section=home';</script>";
    exit;
}



?>

<div class="container">
    <?php
    // Products Section
    if ($section === 'products' && isset($_SESSION['role'])) {
        echo '<h2>Products</h2>';
        
        foreach ($products as $product) {
            echo "<div class='product'>
                    <img src='" . htmlspecialchars($product['image']) . "' alt='Product'>
                    <h3>" . htmlspecialchars($product['name']) . "</h3>
                    <p>" . htmlspecialchars($product['description']) . "</p>
                    <p><strong>$" . htmlspecialchars($product['price']) . "</strong></p>";
            if ($_SESSION['role'] === 'buyer') {
                echo "<form method='POST'>
                        <input type='hidden' name='product_id' value='" . $product['id'] . "'>
                        <button type='submit' name='add_to_cart'>Add to Cart</button>
                      </form>";
            }
            echo "</div>";
        }

    // Admin Panel Section
} elseif ($section === 'admin' && $_SESSION['role'] === 'admin') {
    echo '<h2>Admin Panel</h2>';

    // If editing a product
    if (isset($_GET['edit'])) {
        $id = $_GET['edit'];
        $query = "SELECT * FROM products WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product_to_edit = $result->fetch_assoc();

        if ($product_to_edit) {
            echo '<h3>Edit Product</h3>';
            echo '<form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="product_id" value="' . $product_to_edit['id'] . '">
                    <input type="text" name="name" value="' . htmlspecialchars($product_to_edit['name']) . '" required>
                    <textarea name="description" required>' . htmlspecialchars($product_to_edit['description']) . '</textarea>
                    <input type="file" name="image" accept="image/*">
                    <input type="number" step="0.01" name="price" value="' . htmlspecialchars($product_to_edit['price']) . '" required>
                    <button type="submit" name="update_product">Update Product</button>
                  </form>';
        } else {
            echo '<p>Product not found.</p>';
        }
    } else {
        // Add Product Form
        echo '<form method="POST" enctype="multipart/form-data">
                <input type="text" name="name" placeholder="Product Name" required>
                <textarea name="description" placeholder="Description" required></textarea>
                <input type="file" name="image" accept="image/*" required>
                <input type="number" step="0.01" name="price" placeholder="Price" required>
                <input type="number" name="stock" placeholder="Stock Quantity" required>
                <button type="submit" name="add_product">Add Product</button>
                
              </form>';
    }

    // Display Existing Products
    echo '<h3>Existing Products</h3>';
    foreach ($products as $product) {
        echo "<div class='product'>
                <img src='" . htmlspecialchars($product['image']) . "' alt='Product Image' style='max-width: 150px;'/>
                <h3>" . htmlspecialchars($product['name']) . "</h3>
                <p>" . htmlspecialchars($product['description']) . "</p>
                <p><strong>$" . htmlspecialchars($product['price']) . "</strong></p>
                <a href='index.php?section=admin&edit=" . $product['id'] . "'>Edit</a>
                <a href='index.php?section=admin&delete=" . $product['id'] . "'>Delete</a>
              </div>";
    }

    // Cart Section
    } elseif ($section === 'cart') {
        echo '<h2>Your Cart</h2>';
        if (count($cart_items) > 0) {
            echo '<table class="cart-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>';
            $total = 0;
            foreach ($cart_items as $item) {
                $product = $item;
                $total += $product['price'];
                echo "<tr>
                        <td>" . htmlspecialchars($product['name']) . "</td>
                        <td>$" . number_format($product['price'], 2) . "</td>
                        <td>
                            <form method='POST'>
                                <input type='hidden' name='product_id' value='" . $product['id'] . "'>
                                <button type='submit' name='remove_from_cart'>Remove</button>
                            </form>
                        </td>
                      </tr>";
            }
            echo "</tbody></table>";
            echo "<p><strong>Total: $" . number_format($total, 2) . "</strong></p>";
            echo "<form method='POST'>
                    <button type='submit' name='checkout'>Proceed to Checkout</button>
                  </form>";
        } else {
            echo "<p>Your cart is empty.</p>";
        }

    // Checkout Section (Redirect to Confirmation or Home)
    } elseif ($section === 'checkout') {
        echo '<h2>Checkout</h2>';
        // Additional checkout logic if needed

    // Login Section
    } elseif ($section === 'login') {
        echo '<h2>Login</h2>';
        if (isset($error)) echo '<p class="error">' . htmlspecialchars($error) . '</p>';
        echo '<form method="POST">
                <input type="text" name="username" placeholder="Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit" name="login">Login</button>
              </form>';

    // Default Home Section
    } else {
        echo '<h2>Welcome to Cyrus Motor Parts Shop!</h2>';
        echo '<p>Explore our products and find the best motor parts for your needs.</p>';
    }
    ?>
</div>

<?php
// End output buffering
ob_end_flush();
?>




</body>
</html>   
