# Softlink Broker 💰

A modern fintech-style broker platform built with PHP, MySQL, and HTML/CSS. Trade smarter, grow faster.

## 🚀 Features

- ✅ **User Registration System** - Secure signup with email validation
- ✅ **Login System** - Session-based authentication with password hashing
- ✅ **Dashboard** - Personalized user dashboard with account overview
- ✅ **Wallet System** - Track balance and account statistics
- 🔄 **Deposit & Withdrawal** - Coming soon
- 🤖 **AI Support Assistant** - Planned feature
- 📊 **Transaction History** - Track all account activity

## 🛠 Tech Stack

- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+
- **Frontend**: HTML5, CSS3
- **Authentication**: Session-based (can be upgraded to JWT)
- **Hosting**: Railway, Render, or any PHP-compatible server

## 📋 Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
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

Edit `.env` and add your local database credentials:
```env
DB_HOST=localhost
DB_NAME=softlink_broker
DB_USER=root
DB_PASS=your_password
DB_PORT=3306
```

### 3. Create Database
```bash
mysql -u root -p < schema.sql
```

### 4. Run Locally
```bash
# Using PHP built-in server
php -S localhost:8000

# Or use Apache/Nginx
# Point DocumentRoot to project folder
```

Visit `http://localhost:8000` in your browser.

## 🚀 Deploy to Railway (Recommended)

### 1. Create Railway Account
- Go to [railway.app](https://railway.app)
- Sign up with GitHub

### 2. Create New Project
- Click "New Project" → "Deploy from GitHub repo"
- Select this repository

### 3. Add MySQL Service
- Click "+" button in Railway dashboard
- Select "MySQL"
- Railway will automatically provision the database

### 4. Set Environment Variables
In Railway project settings, add:
```env
DB_HOST=your_railway_mysql_host
DB_NAME=railway (or your chosen name)
DB_USER=root
DB_PASS=your_password
DB_PORT=3306
APP_ENV=production
```

### 5. Deploy
Railway automatically deploys from GitHub. Just push to main branch!

## 🚀 Deploy to Render

### 1. Create Render Account
- Go to [render.com](https://render.com)
- Sign up

### 2. Create New Service
- "New" → "Web Service"
- Connect GitHub repository
- Select this repo

### 3. Configure
```
Build Command: (Leave blank - PHP)
Start Command: php -S 0.0.0.0:$PORT
Environment: PHP
```

### 4. Add Database
- Create a new PostgreSQL or MySQL database service
- Link to your web service

### 5. Set Environment Variables
Add in Render dashboard:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT`

## 📁 Project Structure

```
softlink-broker/
├── config/
│   ├── db.php           # Database connection (env-based)
│   ├── auth.php         # Authentication middleware
│   ├── helpers.php      # Helper functions
│   └── logout.php       # Logout handler
├── assets/
│   └── style.css        # Styles
├── index.php            # Landing page
├── register.php         # Registration page
├── login.php            # Login page
├── dashboard.php        # User dashboard (protected)
├── schema.sql           # Database schema
├── .env.example         # Environment template
└── README.md            # This file
```

## 🔐 Security Features

- ✅ Password hashing with `password_hash()`
- ✅ SQL injection prevention with prepared statements
- ✅ Session-based authentication
- ✅ Input validation and sanitization
- ✅ XSS protection with `htmlspecialchars()`
- 🔄 HTTPS enforcement (on Render/Railway)

## 👤 User Workflows

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
- `id` - Primary key
- `fullname`, `email`, `phone` - User info
- `password` - Hashed password
- `balance` - Account balance
- `created_at` - Registration timestamp

### Transactions Table
- `id` - Primary key
- `user_id` - Foreign key to users
- `type` - deposit/withdrawal/trade/fee
- `amount` - Transaction amount
- `status` - pending/completed/failed
- `created_at` - Transaction timestamp

### Login Logs Table
- Tracks user login history
- Records IP address and user agent

## 🔄 Next Steps

1. **Implement Deposits** - Add payment gateway (Stripe, Paystack, Flutterwave)
2. **Implement Withdrawals** - Bank transfer integration
3. **Email Verification** - Send verification link on signup
4. **2FA Support** - Two-factor authentication
5. **Trading Interface** - Buy/sell stocks or crypto
6. **Admin Panel** - System administration
7. **API** - RESTful API for mobile app

## 🐛 Troubleshooting

### Database Connection Error
- Check `.env` file has correct credentials
- Ensure MySQL is running
- Verify user has database privileges

### Session Not Working
- Check PHP `session.save_path` is writable
- Verify cookies are enabled
- Check `session_start()` is called

### 404 Errors on Render/Railway
- Ensure all `.php` files are uploaded
- Check web root is set correctly
- Verify `.htaccess` for URL rewriting (if needed)

## 📝 License

MIT License - Feel free to use this project for learning or commercial purposes.

## 👨‍💻 Author

Richard Ogunyemi (@Lordricho)  
Building the future of fintech in Africa 🚀

## 📧 Support

For issues or questions:
- Open a GitHub issue
- Email: [your-email@example.com]
- Twitter: [@Lordricho]

---

**Status**: 🚧 Active Development  
**Last Updated**: June 2026  
**Version**: 1.0.0-alpha
