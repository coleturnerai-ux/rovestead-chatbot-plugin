# Rovestead Chatbot Email Notifier

WordPress plugin that enables email notifications for the Rovestead AI chatbot.

## Installation

1. **Upload the plugin**:
   - Zip this folder (`rovestead-chatbot.zip`)
   - Go to WordPress Admin → Plugins → Add New → Upload Plugin
   - Choose the zip file and click Install Now
   - Activate the plugin

2. **Configure the plugin**:
   - Go to Settings → Chatbot Notifications
   - Paste the secret key (provided by Bloomfield AI Solutions)
   - Set your notification email (defaults to WordPress admin email)
   - Click Save Settings

3. **Test it**:
   - Click "Send Test Email" button
   - Check your inbox to confirm it works

## What It Does

- Sends you an email when customers request to speak with a human
- Sends error alerts when the chatbot encounters issues
- Uses your existing WordPress email configuration (WP Mail SMTP, etc.)

## Troubleshooting

**Test email not arriving?**
- Check your spam folder
- Verify WordPress can send emails (try resetting your password)
- Install WP Mail SMTP plugin if needed

**"Failed to send email" errors?**
- Check that WordPress email is configured
- Verify the secret key matches your backend .env file

## Support

Contact: Bloomfield AI Solutions
Email: dev@bloomfieldaisolutions.com
