<?php
/**
 * Network Admin overview (NetworkController::render()).
 *
 * @var list<array{id: int, name: string, url: string, active: bool, dashboard: string, indexes: array<string, array{built: bool, documents: ?int, last_modified: ?int}>}> $sites
 */

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1><?php echo esc_html__('Gaffer Search — network', 'gaffer-search'); ?></h1>
    <p><?php echo esc_html__('Every site has its own indexes, secrets and settings. Build and manage them on each site\'s own dashboard.', 'gaffer-search'); ?></p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Site', 'gaffer-search'); ?></th>
                <th><?php echo esc_html__('Gaffer Search', 'gaffer-search'); ?></th>
                <th><?php echo esc_html__('Index', 'gaffer-search'); ?></th>
                <th><?php echo esc_html__('Documents', 'gaffer-search'); ?></th>
                <th><?php echo esc_html__('Last update', 'gaffer-search'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sites as $site) : ?>
            <?php $rows = $site['indexes'] !== [] ? $site['indexes'] : ['' => null]; ?>
            <?php foreach ($rows as $name => $index) : ?>
                <tr>
                    <td><strong><?php echo esc_html($site['name']); ?></strong> <span class="description">#<?php echo (int) $site['id']; ?> · <a href="<?php echo esc_url($site['url']); ?>"><?php echo esc_html($site['url']); ?></a></span></td>
                    <td><?php echo $site['active'] ? esc_html__('active', 'gaffer-search') : esc_html__('not active', 'gaffer-search'); ?></td>
                    <td><?php echo $index === null ? '—' : esc_html( (string) $name); ?></td>
                    <td><?php echo $index === null ? '—' : ($index['built'] ? esc_html(number_format_i18n( (int) $index['documents'])) : esc_html__('not built', 'gaffer-search')); ?></td>
                    <td><?php echo $index !== null && $index['last_modified'] ? esc_html(sprintf(__('%s ago', 'default'), human_time_diff( (int) $index['last_modified']))) : '—'; ?></td>
                    <td><?php if ($site['active']) : ?><a href="<?php echo esc_url($site['dashboard']); ?>"><?php echo esc_html__('Dashboard', 'gaffer-search'); ?></a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
