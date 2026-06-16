# Softlink Broker 💰

A modern fintech-style broker platform built with PHP, PostgreSQL, and HTML/CSS. Trade smarter, grow faster.

## 🚀 Features

- ✅ **User Registration System** - Secure signup with email validation
- ✅ **Login System** - Session-based authentication with password hashing
- ✅ **Dashboard** - Personalized user dashboard with account overview
- ✅ **Wallet System** - Track balance and account statistics
- 🔄 **Deposit & Withdrawal** - Coming soon
- 🤖 **AI Support Assistant** - Planned feature
- 📊 **Transaction History** - Track all account activity

## 🛠 Tech Stack

- **Backend**: PHP 7.4+ (with PDO)
- **Database**: PostgreSQL 12+
- **Frontend**: HTML5, CSS3
- **Authentication**: Session-based (can be upgraded to JWT)
- **Hosting**: Render, Railway, or any PHP-compatible server

## 📋 Requirements

- PHP 7.4 or higher
- PostgreSQL 12 or higher
- Composer (optional, for package management)
- Git

## 🚀 Quick Start (Local Development)

### 1. Clone the Repository
```bash
git clone https://github.com/Lordricho/softlink-broker.git
cd softlink-broker
```

### 2. Set Up Environment Variables
```bash
cp .env.example .env
```

Edit `.env` and add your local PostgreSQL credentials:
```env
DB_HOST=localhost
DB_NAME=softlink_broker
DB_USER=postgres
DB_PASS=your_password
DB_PORT=5432
```

### 3. Create Database
```bash
# Using psql (PostgreSQL command line)
psql -U postgres -f schema.sql

# Or copy-paste schema.sql content into pgAdmin
```

### 4. Run Locally
```bash
# Using PHP built-in server
php -S localhost:8000

# Or use Apache/Nginx
# Point DocumentRoot to project folder
```

Visit `http://localhost:8000` in your browser.

## 🚀 Deploy to Render (Recommended - FREE)

Render offers a **true free tier** with no credit card required.

### 1. Create Render Account
- Go to [render.com](https://render.com)
- Sign up with GitHub

### 2. Create Web Service
- Click **"New +"** → **"Web Service"**
- Connect your GitHub repo: `Lordricho/softlink-broker`
- Configure:
  - **Name**: softlink-broker
  - **Region**: Choose closest to you
  - **Branch**: main
  - **Build Command**: (leave empty)
  - **Start Command**: (leave empty)
- Click **"Create Web Service"**
- Wait 5 minutes for deployment ⏳

### 3. Create PostgreSQL Database
- Click **"New +"** → **"PostgreSQL"**
- Configure:
  - **Name**: softlink-broker-db
  - Leave other settings default
- Click **"Create"**
- Wait 2 minutes for database ⏳

### 4. Connect Database
1. Click on your **web service** (softlink-broker)
2. Go to **"Environment"** tab
3. Copy the connection details from your PostgreSQL service
4. Add environment variables:
   ```
   DB_HOST = (from PostgreSQL Internal Database URL)
   DB_NAME = (from PostgreSQL - usually "postgres")
   DB_USER = (from PostgreSQL)
   DB_PASS = (from PostgreSQL)
   DB_PORT = 5432
   APP_ENV = production
   ```
5. Click **"Save"**
6. Render auto-redeploys

### 5. Your Site is Live! 🎉
- Render gives you a URL: `https://softlink-broker-xxxxx.onrender.com`
- Share it with friends
- Test registration and login

---

## 🚀 Deploy to Railway

### 1. Create Railway Account
- Go to [railway.app](https://railway.app)
- Sign up with GitHub

### 2. Create New Project
- Click **"New Project"** → **"Deploy from GitHub repo"**
- Select `softlink-broker`
- Click **"Deploy"**
- Wait 2-3 minutes ⏳

### 3. Add PostgreSQL Database
- Click **"+"** button
- Select **"PostgreSQL"**
- Railway auto-provisions it
- Wait 1-2 minutes ⏳

### 4. Set Environment Variables
- Click on `softlink-broker` service
- Go to **"Variables"** tab
- Add:
  ```
  DB_HOST = postgresql (Railway internal hostname)
  DB_NAME = (from PostgreSQL details)
  DB_USER = postgres
  DB_PASS = (from PostgreSQL details)
  DB_PORT = 5432
  APP_ENV = production
  ```
- Click **"Save"**

### 5. Deploy & Test
- Railway redeploys automatically
- Your site is live at provided URL

---

## 📁 Project Structure

```
softlink-broker/
├── config/
│   ├── db.php           # PostgreSQL PDO connection
│   ├── auth.php         # Authentication middleware
│   ├── helpers.php      # Helper functions
│   └── logout.php       # Logout handler
├── assets/
│   └── style.css        # Styles
├── index.php            # Landing page
├── register.php         # Registration page
├── login.php            # Login page
├── dashboard.php        # User dashboard (protected)
├── schema.sql           # PostgreSQL database schema
├── .env.example         # Environment template
└── README.md            # This file
```

## 🔐 Security Features

- ✅ Password hashing with `password_hash()`
- ✅ SQL injection prevention with prepared statements (PDO)
- ✅ Session-based authentication
- ✅ Input validation and sanitization
- ✅ XSS protection with `htmlspecialchars()`
- ✅ HTTPS enforcement (on Render/Railway)
- ✅ PostgreSQL ENUM constraints for data integrity

## 👥 User Workflows

### Registration
1. Visit `/register.php`
2. Fill in details (name, email, password)
3. System creates account and logs user in
4. Redirects to dashboard

### Login
1. Visit `/login.php`
2. Enter credentials
3. System validates and creates session
4. Redirects to dashboard

### Dashboard
1. Shows account balance and stats
2. Displays recent transactions
3. Links to deposit/withdrawal (coming soon)
4. Logout button

## 📊 Database Schema

### Users Table
- `id` - Primary key (auto-increment)
- `fullname`, `email`, `phone` - User info
- `password` - Hashed password
- `balance` - Account balance
- `is_verified` - Email verification status
- `created_at`, `updated_at` - Timestamps

### Transactions Table
- `id` - Primary key
- `user_id` - Foreign key to users
- `type` - ENUM: deposit/withdrawal/trade/fee
- `amount` - Transaction amount
- `status` - ENUM: pending/completed/failed
- `reference` - Unique transaction reference
- `created_at` - Transaction timestamp

### Login Logs Table
- Tracks user login history
- Records IP address and user agent

### Wallets Table
- Tracks user wallet balance per currency
- Links to users table

## 🔄 Next Steps

1. **Implement Deposits** - Add payment gateway (Paystack, Flutterwave)
2. **Implement Withdrawals** - Bank transfer integration
3. **Email Verification** - Send verification link on signup
4. **2FA Support** - Two-factor authentication
5. **Trading Interface** - Buy/sell stocks or crypto
6. **Admin Panel** - System administration
7. **API** - RESTful API for mobile app

## 🆘 Troubleshooting

### Database Connection Error
- Check `.env` file has correct credentials
- Ensure PostgreSQL is running
- Verify user has database privileges
- Test connection: `psql -U user -h host -d dbname`

### Session Not Working
- Check PHP `session.save_path` is writable
- Verify cookies are enabled
- Check `session_start()` is called on every page

### 404 Errors on Render/Railway
- Ensure all `.php` files are uploaded
- Check web root is set correctly
- Verify `.htaccess` for URL rewriting (if needed)

### PDO Connection Errors
- Verify PostgreSQL driver is installed: `php -m | grep pdo`
- Check `pgsql` extension is enabled
- Ensure DB_PORT is correct (PostgreSQL default: 5432)

## 📝 License

MIT License - Feel free to use this project for learning or commercial purposes.

## 👨‍💻 Author

Richard Ogunyemi (@Lordricho)  
Building the future of fintech in Africa 🚀

## 📧 Support

For issues or questions:
- Open a GitHub issue
- Email: richard@softlink-broker.com
- Twitter: [@Lordricho]

---

**Status**: 🚧 Active Development  
**Last Updated**: June 2026  
**Version**: 1.1.0-postgresql
