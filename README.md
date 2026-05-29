# CarValue - Internal Car Search Interface

## Background
CarValue is an internal web interface for estimating car values based on year/make/model and mileage based on a data file containing reference inventory, dealers, and zip codes.

## References
- Code Repository: https://github.com/mikeautoscale/mike-carvalue.linkgrid.dev
- Project Requirements: [docs/project-requirements.md](docs/project-requirements.md)
- Sample Data File (1000 lines): [docs/sample-data-1000.txt](docs/sample-data-1000.txt)
- Full Input Data File (1.2GB, 4713915 lines): https://linkgrid.com/downloads/carvalue_project/inventory-listing-2022-08-17.txt
- Nginx configuration file: [conf/mike-carvalue.local.conf](conf/mike-carvalue.local.conf)

## Local Test Environment
- OS: AlmaLinux 9 (running within WSL 2.5.9.0 on Windows 11)
- Database: MySQL (Ver 15.1 Distrib 10.5.29-MariaDB)
- Database Name: mike-carvalue
- Language: PHP 8.0.30 via php-fpm
- Web Server: nginx 1.20.1
- Root Folder: /root/workspace/mike-carvalue.linkgrid.dev
- Public Folder: /root/workspace/mike-carvalue.linkgrid.dev/public
- Host: localhost (this computer)
- Local Nginx Conf: ./conf/mike-carvalue.local.conf
- Local URL: http://localhost:8000/

## Production Environment
- OS: AlmaLinux 9 (running as public VM)
- Database: MySQL (Ver 15.1 Distrib 10.5.27-MariaDB)
- Database Name: mike-carvalue
- Language: PHP 8.0.30 via php-fpm
- Web Server: nginx 1.20.1
- SSH: mike-carvalue.linkgrid.dev (user: root, key: "SSH_KEY" on the Github repository)
- Root Folder: /home/mike-carvalue.linkgrid.dev
- Public Folder: /home/mike-carvalue.linkgrid.dev/public
- Production URL: http://mike-carvalue.linkgrid.dev/
- SSL Certificate: /etc/letsencrypt/live/mike-carvalue.linkgrid.dev/fullchain.pem
- SSL Key: /etc/letsencrypt/live/mike-carvalue.linkgrid.dev/privkey.pem