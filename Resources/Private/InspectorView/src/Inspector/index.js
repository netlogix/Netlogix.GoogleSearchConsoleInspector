import React, {PureComponent} from 'react';
import PropTypes from 'prop-types';
import style from './style.css';
import {dataLoader} from "@neos-project/neos-ui-views";
import { Button, Icon, ToggablePanel } from "@neos-project/react-ui-components";

// Neos 9's neos-ui no longer exposes plow-js via its host vendor API (the
// "@vendor" registry lost its `plow` key), so the extensibility shim for
// 'plow-js' resolves to undefined at runtime. Use a minimal inline
// replacement for the handful of $get(path, obj) calls below instead.
const $get = (path, obj) => path.split('.').reduce((acc, key) => (acc == null ? undefined : acc[key]), obj);

// The host caches data source responses per node for the whole session,
// so a refreshed response has to win over the cached one on revisits.
const refreshed = new Map();

const formatNumber = value => value == null ? '-' : value.toLocaleString();
const formatPercent = value => value == null ? '-' : `${(value * 100).toFixed(1)} %`;
const formatPosition = value => value == null ? '-' : value.toFixed(1);

@dataLoader()
export default class InspectorView extends PureComponent {
    static propTypes = {
        data: PropTypes.shape({
            "data": {
                "inspectionResultLink": PropTypes.string,
                "indexStatusResult": PropTypes.shape({
                    "coverageState": PropTypes.string,
                    "crawledAs": PropTypes.string,
                    "googleCanonical": PropTypes.string,
                    "indexingState": PropTypes.string,
                    "lastCrawlTime": PropTypes.string,
                    "pageFetchState": PropTypes.string,
                    "referringUrls": PropTypes.arrayOf(PropTypes.string),
                    "robotsTxtState": PropTypes.string,
                    "sitemap": PropTypes.array,
                    "userCanonical": PropTypes.string,
                    "verdict": PropTypes.string
                }),
                "performance": PropTypes.shape({
                    "startDate": PropTypes.string,
                    "endDate": PropTypes.string,
                    "current": PropTypes.object,
                    "previous": PropTypes.object,
                    "queries": PropTypes.array
                }),
                "mobileUsabilityResult": PropTypes.shape({
                    "verdict": PropTypes.string
                }),
                "richResultsResult": PropTypes.shape({
                    "verdict": PropTypes.string,
                    "detectedItems": PropTypes.arrayOf(PropTypes.shape({
                        "richResultType": PropTypes.string,
                        "items": PropTypes.arrayOf(PropTypes.shape({
                            "name": PropTypes.string
                        })),
                    }))
                })
            }
        }).isRequired,
        options: PropTypes.shape({
            collection: PropTypes.string,
            columns: PropTypes.array
        }).isRequired
    };

    state = {
        refreshing: false,
        error: null
    };

    refresh = () => {
        const { dataSourcesDataLoader, focusedNodeContextPath, options } = this.props;

        this.setState({ refreshing: true, error: null });
        dataSourcesDataLoader.resolveValue({
            contextNodePath: focusedNodeContextPath,
            dataSourceIdentifier: options.dataSource,
            // the timestamp bypasses the host's response cache
            dataSourceAdditionalData: { node: focusedNodeContextPath, force: Date.now() }
        }).then(response => {
            if (response.data) {
                refreshed.set(focusedNodeContextPath, response.data);
            }
            this.setState({ refreshing: false, error: response.error ? response.error.message : null });
        }).catch(error => this.setState({ refreshing: false, error: error.message }));
    };

    render() {
        const data = refreshed.get(this.props.focusedNodeContextPath) || this.props.data;
        const performance = $get('performance', data);

        const referringUrls = $get('indexStatusResult.referringUrls', data);
        const richItems = $get('richResultsResult.detectedItems', data);

        return (
            <div className={style.view}>
                <Button
                    className={style.viewbutton}
                    style="lighter"
                    title="View in Google Search Console"
                    onClick={() => window.open($get('inspectionResultLink', data), '_blank')}
                >
                    <Icon icon="share" padded="right" />
                    View in Google Search Console
                </Button>

                <Button
                    className={style.viewbutton}
                    style="lighter"
                    title="Fetch current data from Google"
                    disabled={this.state.refreshing}
                    onClick={this.refresh}
                >
                    <Icon icon="sync" spin={this.state.refreshing} padded="right" />
                    Refresh
                </Button>

                {this.state.error && <div className={style.inner}>
                    <Icon icon="exclamation-triangle" color="error" padded="right" />
                    {this.state.error}
                </div>}

                {performance && <div className={style.hr}></div>}

                {performance && <ToggablePanel style="condensed">
                    <ToggablePanel.Header className={style.panelheader}>
                        <Icon icon="chart-line" padded="right" />
                        Search Performance
                    </ToggablePanel.Header>
                    <ToggablePanel.Contents className={style.panelcontents}>
                        <div className={style.inner}>
                            <span className={style.block}>
                                {performance.startDate} to {performance.endDate}, compared to the 28 days before
                            </span>
                            <table className={style.table}>
                                <tbody>
                                    {this.renderMetric('Clicks', 'clicks', formatNumber, performance)}
                                    {this.renderMetric('Impressions', 'impressions', formatNumber, performance)}
                                    {this.renderMetric('CTR', 'ctr', formatPercent, performance)}
                                    {this.renderMetric('Position', 'position', formatPosition, performance, true)}
                                </tbody>
                            </table>
                        </div>
                        {performance.queries.length > 0 && <div className={style.inner}>
                            <table className={style.table}>
                                <thead>
                                    <tr>
                                        <th>Top queries</th>
                                        <th title="Clicks">Cl.</th>
                                        <th title="Impressions">Impr.</th>
                                        <th title="Position">Pos.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {performance.queries.map(row => <tr key={row.query}>
                                        <td>{row.query}</td>
                                        <td>{formatNumber(row.clicks)}</td>
                                        <td>{formatNumber(row.impressions)}</td>
                                        <td>{formatPosition(row.position)}</td>
                                    </tr>)}
                                </tbody>
                            </table>
                        </div>}
                    </ToggablePanel.Contents>
                </ToggablePanel>}

                <div className={style.hr}></div>

                <ToggablePanel style="condensed">
                    <ToggablePanel.Header className={style.panelheader}>
                        <Icon icon="info" padded="right" />
                        Page Status
                    </ToggablePanel.Header>
                    <ToggablePanel.Contents className={style.panelcontents}>
                        <div className={style.inner}>
                            {this.renderVerdict($get('indexStatusResult.verdict', data), $get('indexStatusResult.coverageState', data))}
                            {this.renderVerdict($get('mobileUsabilityResult.verdict', data), 'Mobile Usability')}
                            {this.renderVerdict($get('richResultsResult.verdict', data), 'Rich Results')}

                            {$get('indexStatusResult.lastCrawlTime', data) && <span className={style.block}>
                                Last crawl time &nbsp;
                                {new Date($get('indexStatusResult.lastCrawlTime', data)).toLocaleString()}
                            </span>}
                        </div>
                    </ToggablePanel.Contents>
                </ToggablePanel>

                {$get('indexStatusResult.verdict', data) === 'PASS' && <div className={style.hr}></div>}

                {$get('indexStatusResult.verdict', data) === 'PASS' && <ToggablePanel style="condensed">
                    <ToggablePanel.Header className={style.panelheader}>
                        <Icon icon="link" padded="right" />
                        Canonical
                    </ToggablePanel.Header>
                    <ToggablePanel.Contents className={style.panelcontents}>
                        <div className={style.inner}>
                            {$get('indexStatusResult.googleCanonical', data) && <div>
                                <b className={style.block}>Google Canonical</b>

                                {$get('indexStatusResult.googleCanonical', data)}
                            </div>}

                            {$get('indexStatusResult.userCanonical', data) && <div>
                                <b className={style.block}>User Canonical</b>

                                {$get('indexStatusResult.userCanonical', data)}
                            </div>}
                        </div>
                    </ToggablePanel.Contents>
                </ToggablePanel>}

                {Array.isArray(referringUrls) && referringUrls.length > 0 && <div className={style.hr}></div>}

                {Array.isArray(referringUrls) && referringUrls.length > 0 && <ToggablePanel style="condensed">
                    <ToggablePanel.Header className={style.panelheader}>
                        <Icon icon="share" padded="right" />
                        Referring Urls
                    </ToggablePanel.Header>
                    <ToggablePanel.Contents className={style.panelcontents}>
                        <div className={style.inner}>
                            <ul className={style.list}>{referringUrls.map(url => <li>
                                <a className={style.link} href={url} rel="nofollow,noopener" target="_blank">{url}</a>
                            </li>)}</ul>
                        </div>
                    </ToggablePanel.Contents>
                </ToggablePanel>}

                {$get('indexStatusResult.verdict', data) === 'PASS' && <div className={style.hr}></div>}

                {$get('richResultsResult.verdict', data) === 'PASS' && <ToggablePanel style="condensed">
                    <ToggablePanel.Header className={style.panelheader}>
                        Rich Results
                    </ToggablePanel.Header>
                    <ToggablePanel.Contents className={style.panelcontents}>
                        <div className={style.inner}>
                            {Array.isArray(richItems) && richItems.length > 0 && <div>
                                <ul className={style.list}>{richItems.map(item => this.renderRichItem(item))}</ul>
                            </div>}
                        </div>
                    </ToggablePanel.Contents>
                </ToggablePanel>}
            </div>
        );
    }

    renderMetric(label, key, format, performance, lowerIsBetter = false) {
        const current = performance.current[key];
        const previous = performance.previous[key];
        const diff = current == null || previous == null ? 0 : current - previous;
        const improved = lowerIsBetter ? diff < 0 : diff > 0;

        return <tr>
            <td>{label}</td>
            <td>{format(current)}</td>
            <td className={diff === 0 ? '' : (improved ? style.better : style.worse)} title={`Before: ${format(previous)}`}>
                {diff !== 0 && `${diff > 0 ? '+' : '-'}${format(Math.abs(diff))}`}
            </td>
        </tr>
    }

    renderVerdict(verdict, label) {
        if (!verdict) {
            return '';
        }

        return <span className={style.block}>
            {verdict === 'PASS' && <Icon icon="check" color="primaryBlue" size="lg" padded="right" />}
            {verdict === 'NEUTRAL' && <Icon icon="question" color="warn" size="lg" padded="right" />}
            {verdict === 'FAIL' && <Icon icon="ban" color="error" size="lg" padded="right" />}

            {label}
        </span>
    }

    renderRichItem(item) {
        const renderIssues = () => {
            if (!item.issues) {
                return '';
            }

            return <ul>
                {item.issues.map(issue => <ul>
                    {issue.severity === 'WARNING' && <Icon icon="exclamation-triangle" color="warn" size="ssm" padded="right" />}
                    {issue.severity === 'ERROR' && <Icon icon="exclamation-triangle" color="error" size="sm" padded="right" />}

                    {issue.issueMessage}
                </ul>)}
            </ul>
        };

        return <li className={style.richitem}>
            {item.richResultType && `Type: ${item.richResultType}`}
            {item.name && `Name: ${item.name}`}
            {renderIssues()}

            {item.items && <ul>{item.items.map(subItem => this.renderRichItem(subItem))}</ul>}
        </li>
    }

}
