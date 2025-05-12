# WP OpenTelemetry Metrics

A WordPress plugin that integrates OpenTelemetry observability into your WordPress site. Monitor performance, track errors, and gain insights into your WordPress application with distributed tracing and metrics.

## Features

- 📊 Real-time request latency tracking
- 🚨 Error rate monitoring (404s and other HTTP errors)
- 📈 Visual dashboards with Chart.js
- 🔗 OpenTelemetry integration with span and trace support
- 🎯 Export metrics to Zipkin or other OpenTelemetry collectors
- 🛠️ Fallback mode when OpenTelemetry SDK is not available
- 🔍 Debug mode for troubleshooting
- ⚙️ Configurable settings through WordPress admin

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- PHP cURL extension

## Installation

### Standard Installation

1. Download the plugin zip file
2. Navigate to WordPress Admin → Plugins → Add New
3. Click "Upload Plugin" and select the zip file
4. Click "Install Now" and then "Activate"

### Manual Installation

1. Clone this repository or download the files
2. Upload the `wp-otel-metrics` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin panel

### Composer Installation (Optional)

If you want to use the full OpenTelemetry SDK:

```bash
cd wp-content/plugins/wp-otel-metrics
composer install
```

## Configuration

1. Navigate to WordPress Admin → OTel Metrics
2. Configure the following settings:
   - **OpenTelemetry Endpoint**: Your collector endpoint (default: `http://localhost:9411/api/v2/spans`)
   - **Service Name**: Name for your WordPress service (default: `wordpress`)
   - **Debug Mode**: Enable to log OpenTelemetry operations

## Development Environment with Docker

### Quick Start with Docker Compose

Create a `docker-compose.yml` file in your project root:

```yaml
version: '3.8'

services:
  wordpress:
    image: wordpress:6.4-php8.2-apache
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: mysql:3306
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DEBUG: 'true'
      WORDPRESS_DEBUG_LOG: 'true'
      WORDPRESS_DEBUG_DISPLAY: 'false'
    volumes:
      - ./wp-otel-metrics:/var/www/html/wp-content/plugins/wp-otel-metrics
      - wordpress_data:/var/www/html
    depends_on:
      - mysql
    networks:
      - wp-network

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
    volumes:
      - mysql_data:/var/lib/mysql
    networks:
      - wp-network

  zipkin:
    image: openzipkin/zipkin:latest
    ports:
      - "9411:9411"
    networks:
      - wp-network

  phpmyadmin:
    image: phpmyadmin:latest
    ports:
      - "8081:80"
    environment:
      PMA_HOST: mysql
      PMA_USER: wordpress
      PMA_PASSWORD: wordpress
    depends_on:
      - mysql
    networks:
      - wp-network

volumes:
  wordpress_data:
  mysql_data:

networks:
  wp-network:
    driver: bridge
```

### Running the Development Environment

1. **Start the containers:**
   ```bash
   docker-compose up -d
   ```

2. **Access the services:**
   - WordPress: http://localhost:8080
   - Zipkin UI: http://localhost:9411
   - phpMyAdmin: http://localhost:8081

3. **Complete WordPress installation:**
   - Visit http://localhost:8080
   - Follow the WordPress installation wizard
   - Activate the WP OpenTelemetry Metrics plugin

4. **Configure the plugin:**
   - Go to WordPress Admin → OTel Metrics
   - Set the OpenTelemetry Endpoint to: `http://zipkin:9411/api/v2/spans`
   - Enable Debug Mode for development

### Development Workflow

1. **Make code changes** in the `wp-otel-metrics` directory
2. **View logs:**
   ```bash
   # WordPress logs
   docker-compose logs -f wordpress
   
   # PHP error logs
   docker-compose exec wordpress tail -f /var/www/html/wp-content/debug.log
   ```

3. **View traces in Zipkin:**
   - Open http://localhost:9411
   - Click "Run Query" to see recent traces
   - Click on individual traces to see spans

4. **Stop the environment:**
   ```bash
   docker-compose down
   ```

5. **Reset everything (including data):**
   ```bash
   docker-compose down -v
   ```

## Usage

### Basic Usage

Once activated and configured, the plugin automatically:
- Tracks request latency for all WordPress requests
- Monitors error rates (404s and other errors)
- Sends metrics to your configured OpenTelemetry endpoint

### Viewing Metrics

1. Go to WordPress Admin → OTel Metrics
2. View real-time charts for:
   - Average request latency
   - Error rates
   - Total requests tracked

### Custom Instrumentation

You can add custom spans and metrics in your WordPress code:

```php
// Get trace context
$context = wp_otel_get_trace_context();

// Create a custom span
$span = wp_otel_create_span('custom_operation', [
    'attribute_1' => 'value_1',
    'attribute_2' => 'value_2'
]);

// Your code here...

// End the span
if ($span && method_exists($span, 'end')) {
    $span->end();
}
```

### Debug Mode

Enable debug mode to:
- Log all OpenTelemetry operations to WordPress debug log
- View current trace context in the admin panel
- Troubleshoot integration issues

## Architecture

The plugin consists of several key components:

- **Main Plugin File** (`wp-otel-metrics.php`): Entry point and initialization
- **Metrics Class** (`class-wp-otel-metrics.php`): Core metrics collection
- **Admin Class** (`class-wp-otel-admin.php`): Dashboard and settings interface
- **Integration Class** (`class-wp-otel-integration.php`): OpenTelemetry SDK integration
- **Simple Exporter**: Fallback implementation when SDK is not available

## Troubleshooting

### Common Issues

1. **No data in Zipkin/Collector:**
   - Check endpoint configuration
   - Verify the collector is running and accessible
   - Enable debug mode to see export attempts
   - Check WordPress error logs

2. **PHP errors about missing classes:**
   - Run `composer install` if using full SDK
   - Ensure PHP cURL extension is installed
   - Plugin will fall back to simple implementation automatically

3. **Performance impact:**
   - Metrics collection is lightweight
   - Consider adjusting export frequency in high-traffic sites
   - Use sampling for very high-volume applications

### Logs Location

- WordPress debug log: `/wp-content/debug.log`
- Docker logs: `docker-compose logs wordpress`
- PHP error log: Check your PHP configuration

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Future Enhancements

- [ ] Support for additional metrics (database queries, cache hits)
- [ ] Integration with more OpenTelemetry exporters
- [ ] Sampling configuration
- [ ] Custom metric definitions via admin interface
- [ ] REST API metrics tracking
- [ ] Performance profiling integration

## Author

Phil Wilkinson - [www.philwilky.me](https://www.philwilky.me)

## Acknowledgments

- OpenTelemetry project for the observability framework
- WordPress plugin development best practices
- Chart.js for visualization components
