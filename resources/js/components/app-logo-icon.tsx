import type { SVGAttributes } from 'react';

/** A sheet of rows with a downward arrow: "rows going in". */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M4 3a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h9v-2H4v-4h7v-2H4V9h16v4h2V5a2 2 0 0 0-2-2H4Zm0 2h16v2H4V5Zm14 9h2v4.59l1.3-1.3 1.4 1.42L19 22.41l-3.7-3.7 1.4-1.42 1.3 1.3V14Z"
            />
        </svg>
    );
}
