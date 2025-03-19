- `namespace` 형태에 따라서 폴더와 파일을 선택해서 `include_once` 명령으로 불러옵니다.
- 기본적으로 [PSR-4](https://www.php-fig.org/psr/psr-4/ "PSR-4") 규칙을 준수합니다.

### 테스트 환경
- AWS EC2 Ubuntu 22.04
  - Apache 2.4.52
  - Nginx 1.18.0 (php8.1-fpm)
  - MySQL 8.0.28
  - PHP 8.1.2

### 웹서버 설정
- `php.ini`
  - __extension_dir__ : PHP 확장 라이브러리 경로
  - __extension=pdo_mysql__ : PDO 사용
  - __expose_php=Off__ : PHP 버전 정보 숨김
- `apache2.conf`
  - __ServerSignature Off__ : Apache 서버 정보 숨김
  - __ServerTokens Prod__ : Apache 서버 정보 숨김
  - `.htaccess`
    ```
      <IfModule mod_rewrite.c>
          RewriteEngine on
          RewriteCond %{REQUEST_FILENAME} !-f
          RewriteCond %{REQUEST_FILENAME} !-d
          RewriteCond %{REQUEST_URI} !View/assets
          RewriteRule ^ /index.php [L,QSA] # index.php 에서 모든 경로 처리
      </IfModule>
      ```
- `nginx.conf`
  - __server_tokens off__ : Nginx 서버 정보 숨김 
  - ```
    location / {
        try_files $uri /index.php$is_args$args; # index.php 에서 모든 경로 처리
    }
    
    location ~ \.php$ {
        internal; # .php 확장자로 직접 실행 불가
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock; # php-fpm 버전 확인
    }
    ```

### 파일 구조
- 모든 Controller 파일들은 `Controller.php` 파일을 기본으로 상속 받아서 사용
- 모든 Model 파일들은 `Model.php` 파일을 기본으로 상속 받아서 사용
- `Controller`, `Model`, `View` 외에는 루트 폴더에 다른 경로를 만들지 않음
- `.ini` 파일에 기본 설정 변수값을 지정해서 사용
- `Router.php` 파일에 URL 경로와 클래스, 함수를 연결해서 사용
  - 연결하는 함수가 없는 경우 URL 가장 마지막 값을 함수로 사용