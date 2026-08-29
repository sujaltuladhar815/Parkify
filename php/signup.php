<?php

header("Content-Type: application/json");

include "db.php";

$data = json_decode(file_get_contents("php://input"), true);

$full_name = $data['full_name'];
$email = $data['email'];
$password = $data['password'];


// Check if email already exists
$check = "SELECT * FROM users WHERE email='$email'";
$result = mysqli_query($conn, $check);

if(mysqli_num_rows($result) > 0){
    echo json_encode([
        "success" => false,
        "message" => "Email already exists"
    ]);
    exit();
}


// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);


// Insert user
$sql = "INSERT INTO users(full_name, email, password)
VALUES('$full_name', '$email', '$hashed_password')";

if(mysqli_query($conn, $sql)){

    echo json_encode([
        "success" => true,
        "message" => "Account created successfully"
    ]);

}else{

    echo json_encode([
        "success" => false,
        "message" => "Signup failed"
    ]);
}

?>