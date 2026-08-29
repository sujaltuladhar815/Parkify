<?php

header("Content-Type: application/json");

include "db.php";

$data = json_decode(file_get_contents("php://input"), true);

$email = $data['email'];
$password = $data['password'];


// Find user
$sql = "SELECT * FROM users WHERE email='$email'";

$result = mysqli_query($conn, $sql);

if(mysqli_num_rows($result) == 0){

    echo json_encode([
        "success" => false,
        "message" => "User not found"
    ]);

    exit();
}


$user = mysqli_fetch_assoc($result);


// Verify password
if(password_verify($password, $user['password'])){

    echo json_encode([
        "success" => true,
        "message" => "Login successful",

        "user" => [
            "id" => $user['user_id'],
            "name" => $user['full_name'],
            "email" => $user['email']
        ]
    ]);

}else{

    echo json_encode([
        "success" => false,
        "message" => "Invalid password"
    ]);
}

?>