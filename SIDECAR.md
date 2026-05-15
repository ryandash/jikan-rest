# Anime Update Sidecar

The Anime Update Sidecar is a background service that automatically runs daily to:
1. Update anime data in MongoDB based on airing status
2. Run AnimeSweepIndexer to clean up index data
3. Run AnimeIndexer to add new anime to the database (starting from where the last run left off)

## Overview

The sidecar runs once per day with the following workflow:

**Step 1: Anime Update**
- Updates ALL currently airing anime
- Updates ALL anime that finished airing less than X months ago (default: 1 month)
- Skips anime that finished airing more than X months ago

**Step 2: AnimeSweepIndexer**
- Cleans up and removes anime that are no longer in the Jikan API

**Step 3: AnimeIndexer**
- Fetches new anime from the Jikan API that aren't yet in the database
- Intelligently starts from the last indexed anime to avoid redundant processing
- Uses the `--last-from-db` option to find the last mal_id in the database and continue from there

## Architecture

### Components

1. **Console Command**: `app/Console/Commands/AnimeUpdateSidecar.php`
   - Orchestrates the three-step daily process
   - Calls each indexer command in sequence
   - Comprehensive error handling and logging

2. **Enhanced AnimeIndexer**: `app/Console/Commands/Indexer/AnimeIndexer.php`
   - New `--last-from-db` option finds the last anime in the database
   - Automatically continues from the next mal_id to avoid reprocessing
   - Saves time by not going through already-indexed anime

3. **Docker Service**: `jikan_anime_update_sidecar` in `docker-compose.yml`
   - Isolated container running the daily sidecar command
   - Uses the same image as the main API
   - Connects to MongoDB and Redis via the jikan_network
   - Automatically restarts if it crashes

4. **Entrypoint Script**: `docker-entrypoint-sidecar.sh`
   - Initializes the environment
   - Starts the sidecar command with configured parameters
   - Controlled via environment variables

## Configuration

### Environment Variables

Configure the sidecar behavior by setting these environment variables in your `.env` file or docker-compose configuration:

| Variable | Default | Description |
|----------|---------|-------------|
| `ANIME_SIDECAR_AIRING_MONTHS` | `1` | Number of months after airing end to stop updating anime |

### Examples

#### Update Airing + 6 Months After
```env
ANIME_SIDECAR_AIRING_MONTHS=6
```

#### Update Airing + 3 Months After
```env
ANIME_SIDECAR_AIRING_MONTHS=3
```

## Running the Sidecar

### With Docker Compose

The sidecar starts automatically when you bring up your services:

```bash
docker-compose up -d
```

Check the status:
```bash
docker-compose ps
```

View logs:
```bash
docker-compose logs -f jikan_anime_update_sidecar
```

### Manual Testing

Run the command locally for testing:

```bash
# Using Docker - run the daily cycle once
docker-compose run --rm jikan_anime_update_sidecar php artisan sidecar:anime-update

# With custom airing months threshold
docker-compose run --rm jikan_anime_update_sidecar php artisan sidecar:anime-update --airing-months=3

# Get help
docker-compose run --rm jikan_anime_update_sidecar php artisan sidecar:anime-update --help
```

### Stopping the Sidecar

```bash
docker-compose stop jikan_anime_update_sidecar
```

### Removing the Sidecar Service

Edit `docker-compose.yml` and remove the `jikan_anime_update_sidecar` service block, then:

```bash
docker-compose up -d
```

## Daily Workflow

### Example Output

```
Anime Update Sidecar starting

=== STEP 1: Updating Anime ===
Fetching all anime to update (cutoff date: 2026-04-15)...
Found 150 anime to update
  ✓ Updated anime 1
  ✓ Updated anime 5
  ...
Update complete - Updated: 145, Skipped: 5, Failed: 0

=== STEP 2: Running AnimeSweepIndexer ===
[Sweep output...]

=== STEP 3: Running AnimeIndexer ===
Found last anime in database: MAL ID 52589
Starting from index: 45821
[Indexing output...]

✓ Daily update cycle completed successfully
```

## Update Logic

### Step 1: Anime Update

The sidecar uses the following algorithm:

1. **Calculate Cutoff Date**: Current date minus X months (from `--airing-months` option)

2. **Query Anime**: Find all anime matching:
   ```
   WHERE airing = true 
   OR aired.to >= cutoff_date
   ```

3. **Process Each Anime**:
   - Check if it's still within the update window
   - Skip if it finished airing before the cutoff date
   - Fetch fresh data from Jikan API using `Anime::scrape($mal_id)`
   - Update all fields in MongoDB with the new data
   - Log the update result

4. **Data Updated**: All anime fields are refreshed (title, scores, episodes, status, etc.)

### Step 3: AnimeIndexer with --last-from-db

1. **Find Last Anime**: Query MongoDB for the anime with highest mal_id
2. **Find Position**: Locate that mal_id in the Jikan API cache
3. **Start Indexing**: Resume from the next ID to avoid redundancy
4. **Add New Anime**: All new anime from the API are added to the database

## Data Synchronized

The following fields are updated from the Jikan API:

- Title information (title, title_english, title_japanese, synonyms)
- Airing information (airing status, aired dates, broadcast schedule)
- Content details (episodes, type, source, duration, rating)
- Score and popularity metrics
- Related content (producers, licensors, studios, genres, themes)
- Media attachments (images, trailer, themes)
- Metadata (synopsis, background, approval status)

## Logging

All sidecar operations are logged to your application logs:

```bash
# View sidecar logs
docker-compose logs jikan_anime_update_sidecar

# Follow logs in real-time
docker-compose logs -f jikan_anime_update_sidecar

# View last 50 lines
docker-compose logs --tail=50 jikan_anime_update_sidecar
```

Log entries include:
- Sidecar start with configuration
- Number of anime processed in update phase
- Individual anime update success/failure
- Indexer command outputs
- Errors and warnings with context
- Daily cycle completion status

## Performance Considerations

The sidecar is designed to run once daily and process all qualifying anime in a single batch:

- **Lightweight**: Single-pass processing without loops
- **Comprehensive**: Updates all airing/recent anime in one run
- **Efficient Indexing**: Avoids reprocessing with `--last-from-db`
- **Memory Stable**: No accumulating state across cycles

### Memory Usage

- Base: ~60MB
- Per anime update: ~2-5MB
- Process completes and exits after daily run

### API Rate Limiting

If you hit rate limits from the Jikan API:
1. Increase `ANIME_SIDECAR_AIRING_MONTHS` to reduce update scope
2. Configure a longer interval in the scheduler (cron/supercronic)
3. Run the sidecar less frequently

## Troubleshooting

### Sidecar Not Running

Check logs:
```bash
docker-compose logs jikan_anime_update_sidecar
```

Verify the service is defined:
```bash
docker-compose ps jikan_anime_update_sidecar
```

### Sidecar Not Updating Anime

Check logs for specific errors:
```bash
docker-compose logs -f jikan_anime_update_sidecar --tail=100
```

Common issues:
- MongoDB not accessible: Check network connectivity
- Redis connection failed: Verify Redis is running and healthy
- API rate limit: Jikan may have throttled requests

### High Memory Usage

The sidecar shouldn't consume excessive memory. If it does:
1. Check for large anime records
2. Verify MongoDB is not returning corrupted data
3. Check system resources with `docker stats`

### MongoDB Connection Errors

Ensure the sidecar can reach MongoDB:
```bash
docker-compose exec jikan_anime_update_sidecar php artisan tinker
>>> use MongoDB\Client;
>>> // verify connection
```

### AnimeIndexer Not Starting from Last

If AnimeIndexer always starts from 0:
1. Check that anime exists in the database: `docker-compose exec mongodb mongosh`
2. Verify the `--last-from-db` flag is being passed
3. Check logs for the "Found last anime" message

## Scheduling

### Using Supercronic (Built-in)

The Dockerfile includes supercronic. Configure in `Kernel.php`:

```php
$schedule->command('sidecar:anime-update')
    ->daily()
    ->at('02:00'); // Run at 2 AM
```

### Using External Cron

If running outside Docker:
```bash
# Run daily at 2 AM
0 2 * * * docker-compose -f /path/to/docker-compose.yml run --rm jikan_anime_update_sidecar php artisan sidecar:anime-update
```

### Using Docker Compose Restart Policy

The sidecar service has `restart: unless-stopped`, which will auto-restart if it crashes but won't restart after completing.

## Advanced Usage

### Disable Auto-Start

To prevent the sidecar from starting automatically, comment out the `jikan_anime_update_sidecar` service in `docker-compose.yml`:

```yaml
# jikan_anime_update_sidecar:
#   build: ...
```

### Run Only Specific Steps

To test individual steps:

```bash
# Update anime only
docker-compose run --rm jikan_anime_update_sidecar php artisan sidecar:anime-update

# Run AnimeSweepIndexer only
docker-compose run --rm jikan_anime_update_sidecar php artisan indexer:anime-sweep

# Run AnimeIndexer with --last-from-db
docker-compose run --rm jikan_anime_update_sidecar php artisan indexer:anime --last-from-db
```

### Custom Deployment

For production deployments, consider:
- Running on a separate machine/container
- Scheduling with your orchestrator (Kubernetes CronJob, etc.)
- Monitoring sidecar completion status
- Setting up alerts for failures

## Future Enhancements

Potential improvements to the sidecar:
- Parallel anime updates for faster processing
- Adaptive thresholds based on database size
- Update statistics and metrics reporting
- Integration with monitoring/alerting systems
- Prioritized updates for popular anime first

