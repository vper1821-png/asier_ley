# PDF Download Functionality Fix - Summary

## Issues Fixed

### 1. Replaced wkhtmltopdf with dompdf
- **Problem**: The original code relied on wkhtmltopdf, which was not installed on the system
- **Solution**: Replaced wkhtmltopdf with dompdf, which was already available in the project's vendor directory
- **File**: `/root/asier_ley-main/backend/generate-incident-response-pdf.php`

### 2. Fixed Authentication
- **Problem**: The code used a non-existent JWT class for token verification
- **Solution**: Updated to use the existing Auth class with `Auth::verifyToken()` method
- **File**: `/root/asier_ley-main/backend/generate-incident-response-pdf.php`

### 3. Fixed PHP Syntax Error
- **Problem**: Used PHP expressions inside HEREDOC, which is not allowed
- **Solution**: Moved conditional logic outside the HEREDOC blocks
- **File**: `/root/asier_ley-main/backend/generate-incident-response-pdf.php`

### 4. Fixed File Storage Path
- **Problem**: Code tried to save PDFs to non-existent `../frontend/public/docs` directory
- **Solution**: Updated to save PDFs to the existing `backend/reports/` directory
- **File**: `/root/asier_ley-main/backend/generate-incident-response-pdf.php`

### 5. Added PDF Download Endpoint
- **Problem**: No endpoint existed to serve the generated PDF files
- **Solution**: Added a new route in index.php to serve PDF files with proper security checks
- **File**: `/root/asier_ley-main/backend/index.php`

### 6. Fixed File Permissions
- **Problem**: PDF files might have incorrect permissions preventing web server access
- **Solution**: Added explicit permission setting (0644) and ownership checks
- **File**: `/root/asier_ley-main/backend/generate-incident-response-pdf.php`

### 7. Added Security Checks
- **Problem**: PDF download endpoint lacked proper security validation
- **Solution**: Added path validation, MIME type checking, and file readability checks
- **File**: `/root/asier_ley-main/backend/index.php`

## Key Changes Made

### generate-incident-response-pdf.php
1. Replaced wkhtmltopdf dependency with dompdf library
2. Updated authentication to use Auth::verifyToken() instead of JWT class
3. Fixed HEREDOC syntax errors by moving conditional logic outside
4. Changed PDF storage path from `../frontend/public/docs` to `./reports/`
5. Added directory permission checks (0755) and file permission setting (0644)
6. Added ownership correction for files created as root
7. Updated PDF URL construction to use API_BASE_URL constant
8. Added proper error handling and logging

### index.php
1. Added new route: `/backend/reports/{filename}.pdf` for PDF downloads
2. Implemented security checks:
   - Path validation to prevent directory traversal
   - MIME type verification to ensure files are PDFs
   - File readability check
3. Added proper HTTP headers for PDF download:
   - Content-Type: application/pdf
   - Content-Disposition: attachment
   - Cache control headers
4. Added appropriate error responses (403, 404)

## Testing Results

✓ PDF generation works correctly using dompdf
✓ PDF files are saved to `/var/www/asier_ley-main/backend/reports/`
✓ PDF files have correct permissions (0644) and ownership (www-data:www-data)
✓ PDF download endpoint returns HTTP 200
✓ Downloaded content is valid PDF format
✓ API endpoint returns correct URL with API_BASE_URL
✓ Authentication works correctly with Auth class

## API Endpoint Details

### Generate PDF
- **URL**: `/api/generate-incident-response-pdf`
- **Method**: POST
- **Authentication**: Bearer token required
- **Response**:
  ```json
  {
    "success": true,
    "pdfUrl": "https://leysecurelab.sytes.net/backend/reports/plan-respuesta-incidentes-2026-08-24-185125.pdf",
    "pdfFilename": "plan-respuesta-incidentes-2026-08-24-185125.pdf",
    "html": "...",
    "message": "PDF generado exitosamente"
  }
  ```

### Download PDF
- **URL**: `/backend/reports/{filename}.pdf`
- **Method**: GET
- **Authentication**: None (public download)
- **Response**: Binary PDF file with download headers

## Files Modified

1. `/root/asier_ley-main/backend/generate-incident-response-pdf.php` - Complete rewrite
2. `/root/asier_ley-main/backend/index.php` - Added PDF download route
3. `/var/www/asier_ley-main/backend/generate-incident-response-pdf.php` - Deployed version
4. `/var/www/asier_ley-main/backend/index.php` - Deployed version

## Deployment Notes

The changes have been deployed to both:
- Development location: `/root/asier_ley-main/backend/`
- Production location: `/var/www/asier_ley-main/backend/`

Both locations have been updated with the fixed code and proper file ownership.