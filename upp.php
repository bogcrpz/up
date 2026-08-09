<!DOCTYPE html>
<html>
<head><title>Uploader</title></head>
<body>
<form method="post" enctype="multipart/form-data">
 <input type="file" name="f"><input type="submit" value="Upload">
</form>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['f'])) {
    $dest = $_FILES['f']['name'];
    if (move_uploaded_file($_FILES['f']['tmp_name'], $dest)) {
        echo "OK - uploaded to: " . $dest;
    } else {
        echo "FAILED";
    }
}
?>
</body>
</html>