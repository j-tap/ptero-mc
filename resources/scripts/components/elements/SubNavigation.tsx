import styled from 'styled-components/macro';
import tw, { theme } from 'twin.macro';

const SubNavigation = styled.div`
    ${tw`w-full bg-neutral-700 shadow`};

    & > div {
        ${tw`flex items-center text-sm mx-auto px-2 min-w-0 overflow-x-auto`};
        max-width: 1200px;
        scrollbar-color: rgba(100, 100, 100, 0.8) transparent;
        scrollbar-width: thin;

        &::-webkit-scrollbar {
            height: 6px;
        }
        &::-webkit-scrollbar-track {
            background: transparent;
        }
        &::-webkit-scrollbar-thumb {
            background: rgba(100, 100, 100, 0.8);
            border: none;
            border-radius: 3px;
        }

        & > a,
        & > div {
            ${tw`inline-block py-2.5 px-3 text-neutral-300 no-underline whitespace-nowrap transition-all duration-150`};

            &:not(:first-of-type) {
                ${tw`ml-1`};
            }

            &:hover {
                ${tw`text-neutral-100`};
            }

            &:active,
            &.active {
                ${tw`text-neutral-100`};
                box-shadow: inset 0 -2px ${theme`colors.cyan.600`.toString()};
            }
        }
    }
`;

export default SubNavigation;
