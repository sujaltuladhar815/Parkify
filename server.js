
require("dotenv").config();

const express = require("express");
const cors = require("cors");
const jwt = require("jsonwebtoken");
const { OAuth2Client } = require("google-auth-library");

const app = express();

// ──────────────────────────────────────────────
// Environment Variables
// ──────────────────────────────────────────────
const GOOGLE_CLIENT_ID = process.env.GOOGLE_CLIENT_ID;
const JWT_SECRET = process.env.JWT_SECRET;
const PORT = process.env.PORT || 3000;

// Check required environment variables
if (!GOOGLE_CLIENT_ID) {
  console.error("ERROR: GOOGLE_CLIENT_ID is not defined in .env");
  process.exit(1);
}

if (!JWT_SECRET) {
  console.error("ERROR: JWT_SECRET is not defined in .env");
  process.exit(1);
}

// ──────────────────────────────────────────────
// Google OAuth Client
// ──────────────────────────────────────────────
const googleClient = new OAuth2Client(GOOGLE_CLIENT_ID);

// ──────────────────────────────────────────────
// Middleware
// ──────────────────────────────────────────────
app.use(cors());
app.use(express.json());

// Simple in-memory user list
const users = [];

// ──────────────────────────────────────────────
// POST /api/auth/google
// Frontend sends the Google token here.
// We verify it and send back our own JWT.
// ──────────────────────────────────────────────
app.post("/api/auth/google", async (req, res) => {
  const { credential } = req.body;

  if (!credential) {
    return res.status(400).json({
      error: "No credential provided.",
    });
  }

  // 1. Verify the Google token
  let payload;

  try {
    const ticket = await googleClient.verifyIdToken({
      idToken: credential,
      audience: GOOGLE_CLIENT_ID,
    });

    payload = ticket.getPayload();
  } catch (err) {
    console.error("Google token verification failed:", err);

    return res.status(401).json({
      error: "Invalid Google token.",
    });
  }

  // 2. Get user information from Google
  const {
    sub: googleId,
    email,
    name,
    picture,
  } = payload;

  // 3. Find or create the user
  let user = users.find((u) => u.googleId === googleId);

  if (!user) {
    user = {
      id: users.length + 1,
      googleId,
      email,
      name,
      picture,
    };

    users.push(user);

    console.log("New user:", email);
  } else {
    console.log("Returning user:", email);
  }

  // 4. Create and send JWT
  const token = jwt.sign(
    {
      id: user.id,
      email: user.email,
      name: user.name,
    },
    JWT_SECRET,
    {
      expiresIn: "7d",
    }
  );

  res.json({
    token,
    user,
  });
});

// ──────────────────────────────────────────────
// GET /api/auth/profile
// Protected route — requires JWT
// ──────────────────────────────────────────────
app.get("/api/auth/profile", (req, res) => {
  const header = req.headers.authorization;

  if (!header) {
    return res.status(401).json({
      error: "No token.",
    });
  }

  const token = header.split(" ")[1];

  if (!token) {
    return res.status(401).json({
      error: "Invalid authorization header.",
    });
  }

  try {
    const decoded = jwt.verify(token, JWT_SECRET);

    const user = users.find((u) => u.id === decoded.id);

    if (!user) {
      return res.status(404).json({
        error: "User not found.",
      });
    }

    res.json({
      user,
    });
  } catch (err) {
    return res.status(401).json({
      error: "Invalid or expired token.",
    });
  }
});

// ──────────────────────────────────────────────
// Home Route
// ──────────────────────────────────────────────
app.get("/", (req, res) => {
  res.send("Parkify backend is running");
});

// ──────────────────────────────────────────────
// Start Server
// ──────────────────────────────────────────────
app.listen(PORT, () => {
  console.log(`Parkify backend running at http://localhost:${PORT}`);
});
