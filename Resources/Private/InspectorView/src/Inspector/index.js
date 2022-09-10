import React, {PureComponent} from 'react';
import PropTypes from 'prop-types';
import {$get} from 'plow-js';
import style from './style.css';
import {dataLoader} from "@neos-project/neos-ui-views";
import { Button, Icon, ToggablePanel } from "@neos-project/react-ui-components";

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

    render() {
        const { data } = this.props;

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
